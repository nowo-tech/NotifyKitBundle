<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Contract\Event\NotificationReadEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationRecordedEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationSkippedEvent;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Fixtures\BinaryIdListener;
use Nowo\NotifyKitBundle\Tests\Fixtures\Ddd\Member;
use Nowo\NotifyKitBundle\Tests\Fixtures\Ddd\MemberRecipientResolver;
use Nowo\NotifyKitBundle\Tests\Fixtures\FlushSpy;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectAccessChecker;
use Nowo\NotifyKitBundle\Tests\Fixtures\RecordingListener;
use Nowo\NotifyKitBundle\Tests\Fixtures\RecordingPublisher;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use Nowo\NotifyKitBundle\Tests\Fixtures\TestTypeProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

use function array_replace_recursive;
use function assert;
use function dirname;
use function getenv;
use function is_string;

/**
 * The two host kinds of the functional suite (architecture bar): a standard Symfony app (attribute `User` entity,
 * default entity manager, the bundle's Doctrine recipient resolver, no access checker: personal rows only) and a DDD
 * app (plain XML-mapped `Member` on a second entity manager `ddd`, `auto_mapping: false` with the bundle's XML
 * registered by the host, its own resolver and access checker, another table prefix).
 */
final readonly class Host
{
    public const STANDARD = 'standard';

    public const DDD = 'ddd';

    /** Fixed start time of the test clock. */
    public const NOW = '2026-11-15 10:00:00';

    private function __construct(public string $kind)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public static function ddd(): self
    {
        return new self(self::DDD);
    }

    /**
     * `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL, PostgreSQL) or a SQLite file.
     */
    public static function databaseUrl(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        (new Filesystem())->mkdir(TestKernel::baseDir());

        return 'sqlite:///' . TestKernel::baseDir() . '/functional.sqlite';
    }

    /**
     * Whether the suite runs on a server database (MySQL / PostgreSQL), not SQLite.
     */
    public static function onServerDatabase(): bool
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');

        return is_string($url) && $url !== '';
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public function bundleConfig(array $override = []): array
    {
        $common = [
            'enabled' => true,
            'types'   => [
                'invitation_accepted' => ['category' => 'account', 'icon' => 'tabler:mail-check'],
                'billing.ready'       => ['category' => 'account', 'coalesce' => false],
            ],
            'categories' => ['account' => ['label' => 'notify.category.account', 'group' => 'general', 'order' => 10]],
        ];
        $kind = $this->kind === self::STANDARD
            ? ['recipient' => ['class' => User::class]]
            : [
                'doctrine'  => ['entity_manager' => 'ddd', 'auto_mapping' => false, 'table_prefix' => 'ddd_'],
                'recipient' => ['type' => 'member', 'resolver' => MemberRecipientResolver::class],
                'access'    => ['checker' => ProjectAccessChecker::class, 'default_permission' => 'project.view'],
                'types'     => ['issue.assigned' => ['category' => 'issues', 'permission' => 'issue.view']],
            ];

        return array_replace_recursive($common, $kind, $override);
    }

    /**
     * @return Closure(ContainerConfigurator, string): void
     */
    public function configure(): Closure
    {
        $kind = $this->kind;

        return static function (ContainerConfigurator $container, string $url) use ($kind): void {
            $root = dirname(__DIR__, 3);
            if ($kind === self::STANDARD) {
                $container->extension('doctrine', [
                    'dbal' => ['url' => $url],
                    'orm'  => ['mappings' => ['Fixtures' => ['type' => 'attribute', 'dir' => $root . '/tests/Fixtures/Standard', 'prefix' => 'Nowo\NotifyKitBundle\Tests\Fixtures\Standard', 'is_bundle' => false]]],
                ]);
            } else {
                $container->extension('doctrine', [
                    'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $url], 'ddd' => ['url' => $url]]],
                    'orm'  => [
                        'default_entity_manager' => 'default',
                        'entity_managers'        => [
                            'default' => ['connection' => 'default'],
                            'ddd'     => ['connection' => 'ddd', 'mappings' => [
                                'Members'   => ['type' => 'xml', 'dir' => $root . '/tests/Fixtures/Ddd/mapping', 'prefix' => 'Nowo\NotifyKitBundle\Tests\Fixtures\Ddd', 'is_bundle' => false],
                                'NotifyKit' => ['type' => 'xml', 'dir' => $root . '/src/Resources/config/doctrine', 'prefix' => 'Nowo\NotifyKitBundle\Domain', 'is_bundle' => false],
                            ]],
                        ],
                    ],
                ]);
                $container->services()->set(MemberRecipientResolver::class);
                $container->services()->set(ProjectAccessChecker::class)->args([[
                    'member:m-1|issue.view'   => ['project:42'],
                    'member:m-1|project.view' => ['project:42'],
                    'member:m-2|task.view'    => ['project:7'],
                ]]);
                $container->services()->set(TestTypeProvider::class)->autoconfigure();
            }
            $container->services()->set('clock', MockClock::class)->args([self::NOW, 'UTC'])->public();
            $container->services()->set(RecordingListener::class)->public()
                ->tag('kernel.event_listener', ['event' => NotificationRecordedEvent::class, 'method' => 'onEvent'])
                ->tag('kernel.event_listener', ['event' => NotificationSkippedEvent::class, 'method' => 'onEvent'])
                ->tag('kernel.event_listener', ['event' => NotificationReadEvent::class, 'method' => 'onEvent']);
            $container->services()->set(RecordingPublisher::class)->public();
            $container->services()->set(FlushSpy::class)->public()->tag('doctrine.event_listener', ['event' => 'onFlush']);
            $container->services()->set(BinaryIdListener::class)->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        };
    }

    public function entityManagerName(): string
    {
        return $this->kind === self::STANDARD ? 'default' : 'ddd';
    }

    public function tablePrefix(): string
    {
        return $this->kind === self::STANDARD ? 'nowo_' : 'ddd_';
    }

    public function recipientType(): string
    {
        return $this->kind === self::STANDARD ? 'user' : 'member';
    }

    public function entityManager(TestKernel $kernel): EntityManagerInterface
    {
        $doctrine = $kernel->getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager($this->entityManagerName());
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Drops and creates the host tables and the bundle tables on the configured database.
     */
    public function resetSchema(TestKernel $kernel): void
    {
        $em       = $this->entityManager($kernel);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function recipient(string $id): RecipientRef
    {
        return new RecipientRef($this->recipientType(), $id);
    }

    /**
     * Persists a host user through the host's own entity manager.
     */
    public function createUser(TestKernel $kernel, string $id): object
    {
        $em   = $this->entityManager($kernel);
        $user = $this->kind === self::STANDARD ? new User($id) : new Member($id);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
