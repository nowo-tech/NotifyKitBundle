<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Fixtures\AuditKit\AuditLogEntry;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function assert;
use function dirname;

/**
 * Audit ZAL-1 with the real AuditKit 2.x bundle: the bridge never flushes the host's entity manager. In AuditKit's
 * `host` flush mode the bridge is not wired (`auto`), so a preference save leaves a pending host change pending and
 * writes no audit row; `true` refuses to build. In `isolated` mode the audit row is written through AuditKit's own
 * entity manager and the pending host change still stays pending.
 */
final class AuditKitBridgeKernelTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
    }

    public function testHostFlushModeKeepsTheBridgeOffAndThePendingChangePending(): void
    {
        $kernel = $this->boot('host', 'auto', 'host');
        $this->savePreferenceWithAPendingHostChange($kernel);

        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM app_user'), 'the pending host user was never flushed');
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM audit_log'), 'no bridge, no audit row');
    }

    public function testIsolatedFlushModeAuditsWithoutFlushingTheHost(): void
    {
        $kernel = $this->boot('isolated', 'auto', 'isolated');
        $this->savePreferenceWithAPendingHostChange($kernel);

        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM app_user'), 'the pending host user was never flushed');
        self::assertSame('notify.preferences_changed', $this->connection($kernel)->fetchOne('SELECT action FROM audit_log'));
    }

    public function testTrueRefusesToBuildInHostFlushMode(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('flush_mode: isolated');
        $this->boot('host_true', true, 'host');
    }

    private function savePreferenceWithAPendingHostChange(TestKernel $kernel): void
    {
        $em = Host::standard()->entityManager($kernel);
        $em->persist(new User('pending'));
        $preferences = $kernel->getContainer()->get(PreferencesInterface::class);
        assert($preferences instanceof PreferencesInterface);
        self::assertCount(1, $preferences->save(new RecipientRef('user', 'alice'), new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('account', 'mail'), false)])));
        self::assertCount(1, $em->getUnitOfWork()->getScheduledEntityInsertions(), 'still pending in the host unit of work');
    }

    private function boot(string $id, bool|string $bridge, string $flushMode): TestKernel
    {
        $host   = Host::standard();
        $base   = $host->configure();
        $kernel = new TestKernel('auditkit_' . $id, $host->bundleConfig([
            'types'   => ['invitation_accepted' => ['category' => 'account', 'channels' => ['in_app' => true, 'mail' => true]]],
            'bridges' => ['audit_kit' => $bridge],
        ]), Host::databaseUrl(), static function (ContainerConfigurator $container, string $url) use ($base, $flushMode): void {
            $base($container, $url);
            // AuditKit's blame resolver needs a token storage (no firewall in this kernel).
            $container->services()->set('security.token_storage', TokenStorage::class);
            $container->services()->alias(TokenStorageInterface::class, 'security.token_storage');
            $container->extension('doctrine', ['orm' => ['mappings' => ['AuditFixtures' => ['type' => 'attribute', 'dir' => dirname(__DIR__, 2) . '/Fixtures/AuditKit', 'prefix' => 'Nowo\NotifyKitBundle\Tests\Fixtures\AuditKit', 'is_bundle' => false]]]]);
            $container->extension('nowo_audit_kit', [
                'profiles'   => ['default' => ['user_class' => User::class]],
                'action_log' => ['entity_class' => AuditLogEntry::class, 'flush_mode' => $flushMode],
            ]);
        }, false, null, false, [new NowoAuditKitBundle()]);
        $this->kernels[] = $kernel;
        $kernel->boot();
        $host->resetSchema($kernel);

        return $kernel;
    }

    private function connection(TestKernel $kernel): Connection
    {
        return Host::standard()->entityManager($kernel)->getConnection();
    }
}
