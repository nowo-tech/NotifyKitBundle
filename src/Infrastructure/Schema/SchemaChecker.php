<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Schema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;

use function array_unique;
use function array_values;
use function sprintf;
use function stripos;

/**
 * Compares the live schema of the bundle tables with the mapping (contract §5.4): columns, types, lengths,
 * collations (binary on MySQL / MariaDB), indexes and the table prefix. Uses the ORM schema tool, so the answer is the
 * SQL that would align the bundle tables (statements about other tables are left out).
 */
final readonly class SchemaChecker
{
    /**
     * @param string $entityManager `doctrine.entity_manager`
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityManager = 'default',
        private string $tablePrefix = 'nowo_',
    ) {
    }

    /**
     * The bundle tables, with the prefix.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];
        foreach (NotifyKitMetadataListener::TABLES as $table) {
            $tables[] = $this->tablePrefix . $table;
        }

        return array_values(array_unique($tables));
    }

    /**
     * The SQL that would bring the bundle tables in line with the mapping; empty = in sync.
     *
     * @throws LogicException the bundle mapping is not registered on `doctrine.entity_manager`
     *
     * @return list<string>
     */
    public function differences(): array
    {
        $manager = $this->registry->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('nowo_notify_kit: "%s" is not a Doctrine ORM entity manager (doctrine.entity_manager).', $this->entityManager));
        }
        $classes = [];
        foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata instanceof ClassMetadata && isset(NotifyKitMetadataListener::TABLES[$metadata->getName()])) {
                $classes[] = $metadata;
            }
        }
        if ($classes === []) {
            throw new LogicException(sprintf('nowo_notify_kit: the bundle mapping is not registered on the entity manager "%s" (doctrine.auto_mapping, or register src/Resources/config/doctrine on it).', $this->entityManager));
        }

        $statements = [];
        foreach ((new SchemaTool($manager))->getUpdateSchemaSql($classes) as $sql) {
            foreach ($this->tables() as $table) {
                if (stripos($sql, $table) !== false) {
                    $statements[] = $sql;

                    break;
                }
            }
        }

        return $statements;
    }
}
