<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Domain\RecordOutcome;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;

use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Default {@see NotificationStoreInterface}: plain DBAL on the bundle connection (contract §5.3).
 *
 * - Never flushes an EntityManager and never hydrates a managed entity: rows become {@see Notification} objects by hand.
 * - Writes run inside the transaction of {@see NotifyKitTransactions} and never take a gap lock: coalescing reads the
 *   candidate row, then updates it by primary key with `repeats = repeats + 1` (two concurrent repeats queue on the row
 *   lock and both count; two concurrent first notifications insert two rows at worst, never deadlock); a dedupe key is
 *   claimed with an insert that ignores a held key (`INSERT IGNORE` / `ON CONFLICT DO NOTHING`).
 * - Times are stored and read as UTC (`Y-m-d H:i:s`). Every result selected by recipient is re-checked in PHP (`===`,
 *   audit LQ) before it is returned, updated or deleted.
 *
 * Stateless (worker mode): the connection is resolved per call.
 */
final readonly class DbalNotificationStore implements NotificationStoreInterface
{
    private const COLUMNS = 'id, recipient_type, recipient_id, type, category, actor_type, actor_id, context_type, context_id, target, url, params, repeats, first_created_at, created_at, read_at';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private string $table;

    private string $dedupeTable;

    /**
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(private NotifyKitTransactions $transactions, string $tablePrefix = 'nowo_')
    {
        $this->table       = $tablePrefix . NotifyKitMetadataListener::NOTIFICATION_TABLE;
        $this->dedupeTable = $tablePrefix . NotifyKitMetadataListener::DEDUPE_TABLE;
    }

    /**
     * {@inheritDoc}
     */
    public function record(Notification $draft, ?DateTimeImmutable $repeatSince): RecordOutcome
    {
        $connection = $this->transactions->connection();
        $recipient  = $draft->recipient();
        $actor      = $draft->actor();
        $context    = $draft->context();
        $params     = json_encode($draft->params(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($repeatSince instanceof DateTimeImmutable) {
            // A plain read finds the candidate; the update locks only that row (by primary key, no gap lock) and
            // re-checks it is still unread. Two concurrent first notifications therefore never deadlock (at worst they
            // insert two rows), and two concurrent repeats queue on the row lock and both count.
            $row = $connection->fetchAssociative(
                sprintf('SELECT id, recipient_type, recipient_id, type, target FROM %s WHERE recipient_type = ? AND recipient_id = ? AND type = ? AND target = ? AND read_at IS NULL AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 1', $this->table),
                [$recipient->type, $recipient->id, $draft->type(), $draft->target(), $this->utc($repeatSince)],
            );
            if (is_array($row) && is_string($row['id']) && $row['recipient_type'] === $recipient->type && $row['recipient_id'] === $recipient->id
                && $row['type'] === $draft->type() && $row['target'] === $draft->target()
                && (int) $connection->executeStatement(
                    sprintf('UPDATE %s SET category = ?, actor_type = ?, actor_id = ?, context_type = ?, context_id = ?, url = ?, params = ?, repeats = repeats + 1, created_at = ? WHERE id = ? AND read_at IS NULL', $this->table),
                    [$draft->category(), $actor?->type, $actor?->id, $context?->type, $context?->id, $draft->url(), $params, $this->utc($draft->createdAt()), $row['id']],
                ) === 1) {
                return new RecordOutcome(new NotificationId($row['id']), true);
            }
        }

        $connection->insert($this->table, [
            'id'               => $draft->id()->value,
            'recipient_type'   => $recipient->type,
            'recipient_id'     => $recipient->id,
            'type'             => $draft->type(),
            'category'         => $draft->category(),
            'actor_type'       => $actor?->type,
            'actor_id'         => $actor?->id,
            'context_type'     => $context?->type,
            'context_id'       => $context?->id,
            'target'           => $draft->target(),
            'url'              => $draft->url(),
            'params'           => $params,
            'repeats'          => $draft->repeats(),
            'first_created_at' => $this->utc($draft->firstCreatedAt()),
            'created_at'       => $this->utc($draft->createdAt()),
            'read_at'          => null,
        ], ['repeats' => ParameterType::INTEGER]);

        return new RecordOutcome($draft->id(), false);
    }

    /**
     * {@inheritDoc}
     */
    public function claimDedupe(string $keyHash, DateTimeImmutable $now, DateTimeImmutable $until): bool
    {
        // No locking read (a gap lock on a missing key deadlocks two concurrent claims on MySQL): take an expired
        // marker over, else insert, ignoring a key another request holds (it waits for that request to end).
        $connection = $this->transactions->connection();
        if ((int) $connection->executeStatement(
            sprintf('UPDATE %s SET expires_at = ? WHERE key_hash = ? AND expires_at <= ?', $this->dedupeTable),
            [$this->utc($until), $keyHash, $this->utc($now)],
        ) === 1) {
            return true;
        }
        $mysql  = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $insert = $mysql ? 'INSERT IGNORE INTO %s (key_hash, expires_at) VALUES (?, ?)' : 'INSERT INTO %s (key_hash, expires_at) VALUES (?, ?) ON CONFLICT (key_hash) DO NOTHING';

        return (int) $connection->executeStatement(sprintf($insert, $this->dedupeTable), [$keyHash, $this->utc($until)]) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetContext(ContextRef $context, int $limit): int
    {
        $connection = $this->transactions->connection();
        $rows       = $connection->fetchAllAssociative(
            sprintf('SELECT id, context_type, context_id FROM %s WHERE context_type = ? AND context_id = ? ORDER BY id LIMIT %d', $this->table, $limit),
            [$context->type, $context->id],
        );
        $ids = [];
        foreach ($rows as $row) {
            if ($row['context_type'] === $context->type && $row['context_id'] === $context->id) {
                $ids[] = $row['id'];
            }
        }

        return $this->deleteIds($connection, $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteReadBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $connection = $this->transactions->connection();
        $ids        = $connection->fetchFirstColumn(
            sprintf('SELECT id FROM %s WHERE read_at IS NOT NULL AND created_at < ? ORDER BY created_at, id LIMIT %d', $this->table, $limit),
            [$this->utc($cutoff)],
        );

        return $this->deleteIds($connection, $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function recipientsOver(int $keep, ?RecipientRef $after, int $limit): array
    {
        $where  = '';
        $params = [];
        if ($after instanceof RecipientRef) {
            $where  = ' WHERE recipient_type > ? OR (recipient_type = ? AND recipient_id > ?)';
            $params = [$after->type, $after->type, $after->id];
        }
        $params[] = $keep;
        $rows     = $this->transactions->connection()->fetchAllAssociative(
            sprintf('SELECT recipient_type, recipient_id FROM %s%s GROUP BY recipient_type, recipient_id HAVING COUNT(*) > ? ORDER BY recipient_type, recipient_id LIMIT %d', $this->table, $where, $limit),
            $params,
            [count($params) - 1 => ParameterType::INTEGER],
        );
        $recipients = [];
        foreach ($rows as $row) {
            $recipients[] = new RecipientRef((string) $row['recipient_type'], (string) $row['recipient_id']);
        }

        return $recipients;
    }

    /**
     * {@inheritDoc}
     */
    public function trimRecipient(RecipientRef $recipient, int $keep, int $limit): int
    {
        $connection = $this->transactions->connection();
        $rows       = $connection->fetchAllAssociative(
            sprintf('SELECT id, recipient_type, recipient_id FROM %s WHERE recipient_type = ? AND recipient_id = ? ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $this->table, $limit, $keep),
            [$recipient->type, $recipient->id],
        );
        $ids = [];
        foreach ($rows as $row) {
            if ($row['recipient_type'] === $recipient->type && $row['recipient_id'] === $recipient->id) {
                $ids[] = $row['id'];
            }
        }

        return $this->deleteIds($connection, $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteExpiredDedupe(DateTimeImmutable $now, int $limit): int
    {
        $connection = $this->transactions->connection();
        $hashes     = $connection->fetchFirstColumn(
            sprintf('SELECT key_hash FROM %s WHERE expires_at <= ? ORDER BY expires_at, key_hash LIMIT %d', $this->dedupeTable, $limit),
            [$this->utc($now)],
        );
        if ($hashes === []) {
            return 0;
        }

        return (int) $connection->executeStatement(
            sprintf('DELETE FROM %s WHERE key_hash IN (?)', $this->dedupeTable),
            [array_values($hashes)],
            [ArrayParameterType::STRING],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function ofRecipient(RecipientRef $recipient, int $limit): array
    {
        $rows = $this->transactions->connection()->fetchAllAssociative(
            sprintf('SELECT %s FROM %s WHERE recipient_type = ? AND recipient_id = ? ORDER BY created_at DESC, id DESC LIMIT %d', self::COLUMNS, $this->table, $limit),
            [$recipient->type, $recipient->id],
        );
        $notifications = [];
        foreach ($rows as $row) {
            if ($row['recipient_type'] === $recipient->type && $row['recipient_id'] === $recipient->id) {
                $notifications[] = $this->hydrate($row);
            }
        }

        return $notifications;
    }

    /**
     * {@inheritDoc}
     */
    public function countUnread(RecipientRef $recipient): int
    {
        $rows = $this->transactions->connection()->fetchAllAssociative(
            sprintf('SELECT recipient_type, recipient_id FROM %s WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL', $this->table),
            [$recipient->type, $recipient->id],
        );
        $count = 0;
        foreach ($rows as $row) {
            if ($row['recipient_type'] === $recipient->type && $row['recipient_id'] === $recipient->id) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<mixed> $ids
     */
    private function deleteIds(Connection $connection, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $connection->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $this->table), [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Notification
    {
        $params = is_string($row['params']) ? json_decode($row['params'], true, 4, JSON_THROW_ON_ERROR) : [];
        $clean  = [];
        foreach (is_array($params) ? $params : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }
        $actor   = is_string($row['actor_type']) && is_string($row['actor_id']) ? new RecipientRef($row['actor_type'], $row['actor_id']) : null;
        $context = is_string($row['context_type']) && is_string($row['context_id']) ? new ContextRef($row['context_type'], $row['context_id']) : null;

        return new Notification(
            new NotificationId((string) $row['id']),
            new RecipientRef((string) $row['recipient_type'], (string) $row['recipient_id']),
            (string) $row['type'],
            (string) $row['category'],
            $actor,
            $context,
            (string) $row['target'],
            (string) $row['url'],
            $clean,
            is_numeric($row['repeats']) ? (int) $row['repeats'] : 1,
            $this->date($row['first_created_at']),
            $this->date($row['created_at']),
            $row['read_at'] === null ? null : $this->date($row['read_at']),
        );
    }

    private function date(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }
}
