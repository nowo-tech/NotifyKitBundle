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
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\InboxStatus;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\BatchResult;
use Nowo\NotifyKitBundle\Domain\InboxCursor;
use Nowo\NotifyKitBundle\Domain\InboxStatistics;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Domain\RecordOutcome;
use Nowo\NotifyKitBundle\Domain\Visibility;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;

use function array_fill;
use function array_values;
use function count;
use function implode;
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
 *   claimed in one upsert (`ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE … WHERE`) that takes over only an expired
 *   marker.
 * - Identifier equality is exact before any LIMIT / OFFSET / GROUP BY (byte comparison on MySQL, NO PAD collations
 *   from the metadata listener); walks that delete in batches follow the rows fetched, not the rows deleted.
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
            [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id, 'type' => $draft->type(), 'target' => $draft->target()]);
            $row              = $connection->fetchAssociative(
                sprintf('SELECT id, recipient_type, recipient_id, type, target FROM %s WHERE %s AND read_at IS NULL AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 1', $this->table, $where),
                [...$values, $this->utc($repeatSince)],
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
        // One statement (audit QL-L1): an insert, or the takeover of an expired marker, never a locking read or an
        // update of a missing key (a gap lock on MySQL deadlocks two concurrent first claims).
        $connection = $this->transactions->connection();
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // Affected rows: 1 = inserted, 2 = expired marker taken over, 0 = a live marker holds the key.
            $affected = (int) $connection->executeStatement(
                sprintf('INSERT INTO %s (key_hash, expires_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE expires_at = IF(expires_at <= ?, ?, expires_at)', $this->dedupeTable),
                [$keyHash, $this->utc($until), $this->utc($now), $this->utc($until)],
            );

            return $affected > 0;
        }

        return (int) $connection->executeStatement(
            sprintf('INSERT INTO %1$s (key_hash, expires_at) VALUES (?, ?) ON CONFLICT (key_hash) DO UPDATE SET expires_at = EXCLUDED.expires_at WHERE %1$s.expires_at <= ?', $this->dedupeTable),
            [$keyHash, $this->utc($until), $this->utc($now)],
        ) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetContext(ContextRef $context, ?string $afterId, int $limit): BatchResult
    {
        // Keyset over the rows fetched (audit QL-M3): a row the PHP re-check refuses never stops the walk.
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['context_type' => $context->type, 'context_id' => $context->id]);
        if ($afterId !== null) {
            $where .= ' AND id > ?';
            $values[] = $afterId;
        }
        $rows = $connection->fetchAllAssociative(sprintf('SELECT id, context_type, context_id FROM %s WHERE %s ORDER BY id LIMIT %d', $this->table, $where, $limit), $values);
        $ids  = [];
        $last = null;
        foreach ($rows as $row) {
            $last = (string) $row['id'];
            if ($row['context_type'] === $context->type && $row['context_id'] === $context->id) {
                $ids[] = $row['id'];
            }
        }

        return new BatchResult($this->deleteIds($connection, $ids), count($rows) >= $limit ? $last : null);
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
    public function recipientsOver(int $keep, ?array $after, int $limit): array
    {
        // Grouped by the exact bytes of the id on MySQL (audit QL-M3): look-alikes never form one group.
        $connection = $this->transactions->connection();
        $id         = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'CAST(recipient_id AS BINARY)' : 'recipient_id';
        $where      = '';
        $params     = [];
        if ($after !== null) {
            $where  = sprintf(' WHERE recipient_type > ? OR (recipient_type = ? AND %s > ?)', $id);
            $params = [$after[0], $after[0], $after[1]];
        }
        $params[] = $keep;
        $rows     = $connection->fetchAllAssociative(
            sprintf('SELECT recipient_type, %1$s AS rid FROM %2$s%3$s GROUP BY recipient_type, %1$s HAVING COUNT(*) > ? ORDER BY recipient_type, %1$s LIMIT %4$d', $id, $this->table, $where, $limit),
            $params,
            [count($params) - 1 => ParameterType::INTEGER],
        );
        $recipients = [];
        foreach ($rows as $row) {
            $recipients[] = [(string) $row['recipient_type'], (string) $row['rid']];
        }

        return $recipients;
    }

    /**
     * {@inheritDoc}
     */
    public function trimRecipient(string $recipientType, string $recipientId, int $keep, int $limit): int
    {
        // The exact match runs in SQL before OFFSET / LIMIT (audit QL-M3), then again in PHP.
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipientType, 'recipient_id' => $recipientId]);
        $rows             = $connection->fetchAllAssociative(
            sprintf('SELECT id, recipient_type, recipient_id FROM %s WHERE %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $this->table, $where, $limit, $keep),
            $values,
        );
        $ids = [];
        foreach ($rows as $row) {
            if ($row['recipient_type'] === $recipientType && $row['recipient_id'] === $recipientId) {
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
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        $rows             = $connection->fetchAllAssociative(sprintf('SELECT %s FROM %s WHERE %s ORDER BY created_at DESC, id DESC LIMIT %d', self::COLUMNS, $this->table, $where, $limit), $values);
        $notifications    = [];
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
    public function countUnread(RecipientRef $recipient, ?Visibility $visibility = null): int
    {
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        if ($visibility instanceof Visibility) {
            [$filter, $more] = $this->visible($connection, $visibility);
            $where .= ' AND ' . $filter;
            $values = [...$values, ...$more];
        }
        $rows  = $connection->fetchAllAssociative(sprintf('SELECT recipient_type, recipient_id, context_type, context_id, type FROM %s WHERE %s AND read_at IS NULL', $this->table, $where), $values);
        $count = 0;
        foreach ($rows as $row) {
            if ($this->owns($row, $recipient) && (!$visibility instanceof Visibility || $this->allows($visibility, $row))) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function contextTypes(RecipientRef $recipient): array
    {
        // Grouped by the exact bytes on MySQL (audit QL-M3): look-alike contexts never merge into one candidate.
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        $mysql            = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $columns          = [];
        foreach (['recipient_type' => 'rt', 'recipient_id' => 'rid', 'context_type' => 'ct', 'context_id' => 'cid', 'type' => 'ty'] as $column => $alias) {
            $columns[$alias] = $mysql ? 'CAST(' . $column . ' AS BINARY)' : $column;
        }
        $select = [];
        foreach ($columns as $alias => $expression) {
            $select[] = $expression . ' AS ' . $alias;
        }
        $rows = $connection->fetchAllAssociative(
            sprintf('SELECT %s FROM %s WHERE %s AND context_type IS NOT NULL AND context_id IS NOT NULL GROUP BY %s', implode(', ', $select), $this->table, $where, implode(', ', $columns)),
            $values,
        );
        $pairs = [];
        foreach ($rows as $row) {
            if ($row['rt'] !== $recipient->type || $row['rid'] !== $recipient->id) {
                continue;
            }
            try {
                $pairs[] = [new ContextRef((string) $row['ct'], (string) $row['cid']), (string) $row['ty']];
            } catch (InvalidNotificationArgumentException) {
                // A stored context the value object refuses today: its rows stay hidden.
            }
        }

        return $pairs;
    }

    /**
     * {@inheritDoc}
     */
    public function page(RecipientRef $recipient, Visibility $visibility, InboxFilter $filter, ?InboxCursor $after, int $limit): array
    {
        $connection = $this->transactions->connection();
        $exact      = ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id];
        $exact += $filter->type !== null ? ['type' => $filter->type] : [];
        $exact += $filter->category !== null ? ['category' => $filter->category] : [];
        [$where, $values] = $this->exact($connection, $exact);
        [$visible, $more] = $this->visible($connection, $visibility);
        $where .= ' AND ' . $visible;
        $values = [...$values, ...$more];
        if ($filter->status === InboxStatus::Unread) {
            $where .= ' AND read_at IS NULL';
        } elseif ($filter->status === InboxStatus::Read) {
            $where .= ' AND read_at IS NOT NULL';
        }
        if ($after instanceof InboxCursor) {
            $where .= ' AND (created_at < ? OR (created_at = ? AND id < ?))';
            $values = [...$values, $after->createdAt, $after->createdAt, $after->id];
        }
        $rows          = $connection->fetchAllAssociative(sprintf('SELECT %s FROM %s WHERE %s ORDER BY created_at DESC, id DESC LIMIT %d', self::COLUMNS, $this->table, $where, $limit), $values);
        $notifications = [];
        foreach ($rows as $row) {
            if ($this->owns($row, $recipient) && $this->allows($visibility, $row)
                && ($filter->type === null || $row['type'] === $filter->type)
                && ($filter->category === null || $row['category'] === $filter->category)) {
                $notifications[] = $this->hydrate($row);
            }
        }

        return $notifications;
    }

    /**
     * {@inheritDoc}
     */
    public function find(RecipientRef $recipient, NotificationId $id): ?Notification
    {
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['id' => $id->value, 'recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        $row              = $connection->fetchAssociative(sprintf('SELECT %s FROM %s WHERE %s', self::COLUMNS, $this->table, $where), $values);

        return is_array($row) && $row['id'] === $id->value && $this->owns($row, $recipient) ? $this->hydrate($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function changeReadState(RecipientRef $recipient, array $ids, ?DateTimeImmutable $readAt): int
    {
        if ($ids === []) {
            return 0;
        }
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        $state            = $readAt instanceof DateTimeImmutable ? 'read_at IS NULL' : 'read_at IS NOT NULL';
        $marks            = implode(', ', array_fill(0, count($ids), '?'));

        return (int) $connection->executeStatement(
            sprintf('UPDATE %s SET read_at = ? WHERE id IN (%s) AND %s AND %s', $this->table, $marks, $where, $state),
            [$readAt instanceof DateTimeImmutable ? $this->utc($readAt) : null, ...array_values($ids), ...$values],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function unreadIds(RecipientRef $recipient, Visibility $visibility, ?string $afterId, int $limit): array
    {
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        [$visible, $more] = $this->visible($connection, $visibility);
        $where .= ' AND read_at IS NULL AND ' . $visible;
        $values = [...$values, ...$more];
        if ($afterId !== null) {
            $where .= ' AND id > ?';
            $values[] = $afterId;
        }
        $rows = $connection->fetchAllAssociative(sprintf('SELECT id, recipient_type, recipient_id, context_type, context_id, type FROM %s WHERE %s ORDER BY id LIMIT %d', $this->table, $where, $limit), $values);
        $ids  = [];
        $last = null;
        foreach ($rows as $row) {
            $last = (string) $row['id'];
            if ($this->owns($row, $recipient) && $this->allows($visibility, $row)) {
                $ids[] = $last;
            }
        }

        return [$ids, count($rows) >= $limit ? $last : null];
    }

    /**
     * {@inheritDoc}
     */
    public function statistics(DateTimeImmutable $since, DateTimeImmutable $now, int $types): InboxStatistics
    {
        $connection = $this->transactions->connection();
        $totals     = $connection->fetchAssociative(sprintf('SELECT COUNT(*) AS total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread FROM %s', $this->table));
        $markers    = $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE expires_at > ?', $this->dedupeTable), [$this->utc($now)]);
        $byType     = [];
        foreach ($connection->fetchAllAssociative(
            sprintf('SELECT type, COUNT(*) AS n FROM %s WHERE first_created_at >= ? GROUP BY type ORDER BY n DESC, type LIMIT %d', $this->table, $types),
            [$this->utc($since)],
        ) as $row) {
            $byType[(string) $row['type']] = (int) $row['n'];
        }

        return new InboxStatistics(
            is_array($totals) && is_numeric($totals['total']) ? (int) $totals['total'] : 0,
            is_array($totals) && is_numeric($totals['unread']) ? (int) $totals['unread'] : 0,
            is_numeric($markers) ? (int) $markers : 0,
            $byType,
        );
    }

    /**
     * The read-time visibility as SQL (contract §6.1): personal rows, plus the rows of each granted context whose type
     * is one of the granted types there; identifier equality exact as in {@see self::exact()}. The rows read are
     * re-checked in PHP ({@see self::allows()}).
     *
     * @return array{string, list<string>}
     */
    private function visible(Connection $connection, Visibility $visibility): array
    {
        if ($visibility->unrestricted) {
            return ['1 = 1', []];
        }
        $parts  = ['context_type IS NULL'];
        $values = [];
        foreach ($visibility->contexts() as $visible) {
            [$where, $more] = $this->exact($connection, ['context_type' => $visible->context->type, 'context_id' => $visible->context->id]);
            $parts[]        = sprintf('(%s AND type IN (%s))', $where, implode(', ', array_fill(0, count($visible->types), '?')));
            $values         = [...$values, ...$more, ...$visible->types];
        }

        return ['(' . implode(' OR ', $parts) . ')', $values];
    }

    /**
     * Whether a fetched row belongs to $recipient (`===`, audit LQ).
     *
     * @param array<string, mixed> $row
     */
    private function owns(array $row, RecipientRef $recipient): bool
    {
        return $row['recipient_type'] === $recipient->type && $row['recipient_id'] === $recipient->id;
    }

    /**
     * The PHP re-check of the visibility of a fetched row.
     *
     * @param array<string, mixed> $row
     */
    private function allows(Visibility $visibility, array $row): bool
    {
        if ($row['context_type'] === null || $row['context_id'] === null) {
            return true;
        }
        try {
            $context = new ContextRef((string) $row['context_type'], (string) $row['context_id']);
        } catch (InvalidNotificationArgumentException) {
            // A stored context the value object refuses today: hidden for everyone (fail closed).
            return false;
        }

        return $visibility->allows($context, (string) $row['type']);
    }

    /**
     * Equality on identifier columns that is exact before any LIMIT, OFFSET or GROUP BY (REQ-SEC-006 rule 6, audit
     * QL-M3): the plain equality (index) plus, on MySQL / MariaDB, a byte comparison, so a PAD SPACE or case-insensitive
     * column left by a host never folds `'42 '` into `'42'`. PostgreSQL and SQLite compare exactly already.
     *
     * @param array<string, string> $columns
     *
     * @return array{string, list<string>}
     */
    private function exact(Connection $connection, array $columns): array
    {
        $mysql  = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $parts  = [];
        $values = [];
        foreach ($columns as $column => $value) {
            $parts[]  = $column . ' = ?';
            $values[] = $value;
            if ($mysql) {
                $parts[]  = 'CAST(' . $column . ' AS BINARY) = CAST(? AS BINARY)';
                $values[] = $value;
            }
        }

        return [implode(' AND ', $parts), $values];
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
