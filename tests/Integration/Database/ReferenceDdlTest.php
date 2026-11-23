<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Composer\InstalledVersions;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Domain\DedupeMarker;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\StoredPreference;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;

use function dirname;
use function file_get_contents;
use function fwrite;
use function getenv;
use function implode;
use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function trim;

use const STDERR;

/**
 * The reference DDL of docs/INSTALLATION.md is what the mapping generates (contract §5.4), checked on the database of
 * the run: SQLite locally, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in the CI database jobs. On DBAL 4 the block must match exactly;
 * DBAL 3 formats some statements differently, so there every documented table and index name must appear.
 * `NOWO_PRINT_DDL=1` prints the block to paste.
 */
final class ReferenceDdlTest extends KernelTestCase
{
    public function testTheDocumentedDdlMatchesTheMapping(): void
    {
        $host     = Host::standard();
        $kernel   = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $platform = $em->getConnection()->getDatabasePlatform();
        $name     = match (true) {
            $platform instanceof MariaDBPlatform       => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            default                                    => 'sqlite',
        };
        $ddl = implode(";\n", (new SchemaTool($em))->getCreateSchemaSql([$em->getClassMetadata(Notification::class), $em->getClassMetadata(DedupeMarker::class), $em->getClassMetadata(StoredPreference::class)])) . ';';
        if (getenv('NOWO_PRINT_DDL') === '1') {
            fwrite(STDERR, "\n<!-- ddl:" . $name . " -->\n```sql\n" . $ddl . "\n```\n");
        }

        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/INSTALLATION.md');
        self::assertSame(1, preg_match('/<!-- ddl:' . $name . ' -->\n```sql\n(.*?)\n```/s', $doc, $match), 'docs/INSTALLATION.md has the ' . $name . ' block');
        if (str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '4.')) {
            self::assertSame(trim($match[1]), $ddl);

            return;
        }
        preg_match_all('/\b((?:idx_|uniq_)?nowo_notify_[a-z_]+)\b/', $match[1], $names);
        foreach ($names[1] as $object) {
            self::assertStringContainsString($object, $ddl);
        }
    }
}
