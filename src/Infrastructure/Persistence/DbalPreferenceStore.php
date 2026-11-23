<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceRow;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Domain\CellWrite;
use Nowo\NotifyKitBundle\Domain\PreferenceStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;
use Symfony\Component\Uid\Uuid;

use function array_chunk;
use function count;
use function implode;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Default {@see PreferenceStoreInterface}: plain DBAL on the bundle connection (contract §5.1, §5.3), REQ-RUNTIME-003.
 *
 * Every write is **one conditional statement** on the unique cell key, so two writers never lose an update and a stale
 * writer changes nothing:
 *
 * | Expected → wanted | Statement | Conflict when |
 * | --- | --- | --- |
 * | any → row | `INSERT … ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE … WHERE` (an upsert) | never |
 * | any → none | `DELETE` by key | never |
 * | none → row | `INSERT … ON DUPLICATE KEY UPDATE id = id` / `ON CONFLICT DO NOTHING` | 0 rows: a row exists |
 * | row → row' | `UPDATE … WHERE key AND enabled = ? AND scope = ?` | 0 rows: the row changed or is gone |
 * | row → none | `DELETE … WHERE key AND enabled = ? AND scope = ?` | 0 rows |
 * | x → x | plain read of the cell | it no longer holds x |
 *
 * Identifier equality is exact (byte comparison on MySQL next to the NO PAD collations); every row read by recipient
 * is re-checked with `===` in PHP (audit LQ). Stateless (worker mode): the connection is resolved per call.
 */
final readonly class DbalPreferenceStore implements PreferenceStoreInterface
{
    private const COLUMNS = 'recipient_type, recipient_id, context_key, category, channel, enabled, scope';

    private string $table;

    /**
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(private NotifyKitTransactions $transactions, string $tablePrefix = 'nowo_')
    {
        $this->table = $tablePrefix . NotifyKitMetadataListener::PREFERENCE_TABLE;
    }

    /**
     * {@inheritDoc}
     */
    public function rows(RecipientRef $recipient, ?string $contextKey): array
    {
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id]);
        $types            = [];
        if ($contextKey !== null) {
            $where .= ' AND context_key IN (?)';
            $values[] = $contextKey === '' ? [''] : ['', $contextKey];
            $types    = [count($values) - 1 => ArrayParameterType::STRING];
        }
        $rows = $connection->fetchAllAssociative(
            sprintf('SELECT %s FROM %s WHERE %s ORDER BY context_key, category, channel LIMIT %d', self::COLUMNS, $this->table, $where, self::MAX_ROWS),
            $values,
            $types,
        );
        $out = [];
        foreach ($rows as $row) {
            $parsed = $this->parse($row, $recipient);
            if ($parsed instanceof PreferenceRow && ($contextKey === null || $parsed->key->contextKey === '' || $parsed->key->contextKey === $contextKey)) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function rowsOfMany(array $recipients, array $contextKeys, array $categories, array $channels): array
    {
        $connection = $this->transactions->connection();
        $byType     = [];
        foreach ($recipients as $recipient) {
            $byType[$recipient->type][$recipient->key()] = $recipient;
        }
        $out = [];
        foreach ($byType as $type => $people) {
            foreach (array_chunk($people, 500, true) as $chunk) {
                $ids = [];
                foreach ($chunk as $recipient) {
                    $ids[] = $recipient->id;
                }
                $rows = $connection->fetchAllAssociative(
                    sprintf('SELECT %s FROM %s WHERE recipient_type = ? AND recipient_id IN (?) AND context_key IN (?) AND category IN (?) AND channel IN (?)', self::COLUMNS, $this->table),
                    [(string) $type, $ids, $contextKeys, $categories, $channels],
                    [1 => ArrayParameterType::STRING, 2 => ArrayParameterType::STRING, 3 => ArrayParameterType::STRING, 4 => ArrayParameterType::STRING],
                );
                foreach ($rows as $row) {
                    $owner = $chunk[$type . ':' . (is_string($row['recipient_id'] ?? null) ? $row['recipient_id'] : '')] ?? null;
                    if (!$owner instanceof RecipientRef) {
                        continue;
                    }
                    $parsed = $this->parse($row, $owner);
                    if ($parsed instanceof PreferenceRow && in_array($parsed->key->contextKey, $contextKeys, true) && in_array($parsed->key->category, $categories, true) && in_array($parsed->key->channel, $channels, true)) {
                        $out[$owner->key()][] = $parsed;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function write(RecipientRef $recipient, PreferenceKey $key, PreferenceExpectation $expected, ?ChannelPreference $next, DateTimeImmutable $now): CellWrite
    {
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id, 'context_key' => $key->contextKey, 'category' => $key->category, 'channel' => $key->channel]);
        $at               = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if (!$expected->checked) {
            if (!$next instanceof ChannelPreference) {
                return $connection->executeStatement(sprintf('DELETE FROM %s WHERE %s', $this->table, $where), $values) > 0 ? CellWrite::Changed : CellWrite::Unchanged;
            }

            return $this->upsert($connection, $recipient, $key, $next, $at) > 0 ? CellWrite::Changed : CellWrite::Unchanged;
        }

        $old = $expected->stored;
        if ($old instanceof ChannelPreference && $next instanceof ChannelPreference && $old->sameChoice($next)
            || !$old instanceof ChannelPreference && !$next instanceof ChannelPreference) {
            return $this->current($connection, $recipient, $key, $where, $values) === PreferenceExpectation::tokenOf($old) ? CellWrite::Unchanged : CellWrite::Conflict;
        }
        if (!$old instanceof ChannelPreference) {
            // Insert only when the cell has no row (the unique key decides; no locking read, no gap lock held by a read).
            return $this->insertIfAbsent($connection, $recipient, $key, $next, $at) === 1 ? CellWrite::Changed : CellWrite::Conflict;
        }
        $guard  = $where . ' AND enabled = ? AND COALESCE(scope, ?) = ?';
        $params = [...$values, $old->enabled, Scope::All->value, $old->scope->value];
        $types  = [count($values) => ParameterType::BOOLEAN];
        if (!$next instanceof ChannelPreference) {
            return $connection->executeStatement(sprintf('DELETE FROM %s WHERE %s', $this->table, $guard), $params, $types) === 1 ? CellWrite::Changed : CellWrite::Conflict;
        }

        return $connection->executeStatement(
            sprintf('UPDATE %s SET enabled = ?, scope = ?, updated_at = ? WHERE %s', $this->table, $guard),
            [$next->enabled, $next->scope->value, $at, ...$params],
            [0 => ParameterType::BOOLEAN, count($values) + 3 => ParameterType::BOOLEAN],
        ) === 1 ? CellWrite::Changed : CellWrite::Conflict;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteOverrides(RecipientRef $recipient, string $contextKey): array
    {
        $connection       = $this->transactions->connection();
        [$where, $values] = $this->exact($connection, ['recipient_type' => $recipient->type, 'recipient_id' => $recipient->id, 'context_key' => $contextKey]);
        $rows             = $connection->fetchAllAssociative(sprintf('SELECT id, %s FROM %s WHERE %s AND category <> ? LIMIT %d', self::COLUMNS, $this->table, $where, self::MAX_ROWS), [...$values, PreferenceKey::ANY]);
        $ids              = [];
        $keys             = [];
        foreach ($rows as $row) {
            $parsed = $this->parse($row, $recipient);
            if ($parsed instanceof PreferenceRow && $parsed->key->contextKey === $contextKey && !$parsed->key->isSwitch()) {
                $ids[]  = $row['id'];
                $keys[] = $parsed->key;
            }
        }
        if ($ids === []) {
            return [];
        }
        $connection->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $this->table), [$ids], [ArrayParameterType::STRING]);

        return $keys;
    }

    /**
     * The token ({@see PreferenceExpectation::tokenOf()}) of what the cell stores now (plain read).
     *
     * @param list<mixed> $values
     */
    private function current(Connection $connection, RecipientRef $recipient, PreferenceKey $key, string $where, array $values): string
    {
        $row    = $connection->fetchAssociative(sprintf('SELECT %s FROM %s WHERE %s', self::COLUMNS, $this->table, $where), $values);
        $parsed = $row === false ? null : $this->parse($row, $recipient);

        return PreferenceExpectation::tokenOf($parsed instanceof PreferenceRow && $parsed->key->equals($key) ? $parsed->choice() : null);
    }

    private function upsert(Connection $connection, RecipientRef $recipient, PreferenceKey $key, ChannelPreference $next, string $at): int
    {
        [$columns, $marks, $values, $types] = $this->insertParts($recipient, $key, $next, $at);
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // 1 = inserted, 2 = updated, 0 = the row already held these values.
            return (int) $connection->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE updated_at = IF(enabled <=> VALUES(enabled) AND COALESCE(scope, \'all\') = VALUES(scope), updated_at, VALUES(updated_at)), enabled = VALUES(enabled), scope = VALUES(scope)', $this->table, $columns, $marks),
                $values,
                $types,
            );
        }

        return (int) $connection->executeStatement(
            sprintf('INSERT INTO %1$s (%2$s) VALUES (%3$s) ON CONFLICT (recipient_type, recipient_id, context_key, category, channel) DO UPDATE SET enabled = EXCLUDED.enabled, scope = EXCLUDED.scope, updated_at = EXCLUDED.updated_at WHERE %1$s.enabled <> EXCLUDED.enabled OR COALESCE(%1$s.scope, \'all\') <> EXCLUDED.scope', $this->table, $columns, $marks),
            $values,
            $types,
        );
    }

    private function insertIfAbsent(Connection $connection, RecipientRef $recipient, PreferenceKey $key, ChannelPreference $next, string $at): int
    {
        [$columns, $marks, $values, $types] = $this->insertParts($recipient, $key, $next, $at);
        $sql                                = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            // 1 = inserted; 0 = a row holds the key (`id = id` changes nothing).
            ? 'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE id = id'
            : 'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (recipient_type, recipient_id, context_key, category, channel) DO NOTHING';

        return (int) $connection->executeStatement(sprintf($sql, $this->table, $columns, $marks), $values, $types);
    }

    /**
     * @return array{string, string, list<mixed>, array<int<0, max>, ParameterType>}
     */
    private function insertParts(RecipientRef $recipient, PreferenceKey $key, ChannelPreference $next, string $at): array
    {
        return [
            'id, recipient_type, recipient_id, context_key, category, channel, enabled, scope, updated_at',
            '?, ?, ?, ?, ?, ?, ?, ?, ?',
            [Uuid::v7()->toRfc4122(), $recipient->type, $recipient->id, $key->contextKey, $key->category, $key->channel, $next->enabled, $next->scope->value, $at],
            [6 => ParameterType::BOOLEAN],
        ];
    }

    /**
     * A stored row as a value object, when it belongs to $recipient (`===`) and its values are valid; else null.
     *
     * @param array<string, mixed> $row
     */
    private function parse(array $row, RecipientRef $recipient): ?PreferenceRow
    {
        if (($row['recipient_type'] ?? null) !== $recipient->type || ($row['recipient_id'] ?? null) !== $recipient->id
            || !is_string($row['context_key'] ?? null) || !is_string($row['category'] ?? null) || !is_string($row['channel'] ?? null)) {
            return null;
        }
        $scope = $row['scope'] ?? null;
        $scope = $scope === null ? Scope::All : (is_string($scope) ? Scope::tryFrom($scope) : null);
        if (!$scope instanceof Scope) {
            return null;
        }
        try {
            $key = PreferenceKey::stored($row['context_key'], $row['category'], $row['channel']);
        } catch (InvalidNotificationArgumentException) {
            return null;
        }

        return new PreferenceRow($key, (bool) $row['enabled'], $scope);
    }

    /**
     * Equality on every column, plus a byte comparison on MySQL / MariaDB.
     *
     * @param array<string, string> $columns
     *
     * @return array{string, list<mixed>}
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
}
