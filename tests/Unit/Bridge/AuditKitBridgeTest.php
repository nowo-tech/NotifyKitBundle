<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Bridge;

use BackedEnum;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\NotifyKitBundle\Application\Audit\AuditTrailInterface;
use Nowo\NotifyKitBundle\Application\Audit\NullAuditTrail;
use Nowo\NotifyKitBundle\Application\Audit\PreferencesAuditListener;
use Nowo\NotifyKitBundle\Bridge\AuditKit\AuditKitAuditTrail;
use Nowo\NotifyKitBundle\Contract\Event\PreferencesChangedEvent;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\AuditKitBridgePass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_fill;

/**
 * The AuditKit bridge (contract §6.7): what reaches AuditKit's action log (ids, keys and counts only), the listener of
 * {@see PreferencesChangedEvent}, and the compiler pass (`bridges.audit_kit`: auto / true / false).
 */
final class AuditKitBridgeTest extends TestCase
{
    public function testThePreferenceChangeIsRecordedWithKeysOnly(): void
    {
        $recorder = $this->recorder();
        $listener = new PreferencesAuditListener(new AuditKitAuditTrail($recorder));
        $keys     = array_fill(0, 60, PreferenceKey::account('issues', 'mail'));
        $listener(new PreferencesChangedEvent(new RecipientRef('user', '7'), $keys));

        self::assertCount(1, $recorder->calls);
        [$action, $actor, $subject, $context] = $recorder->calls[0];
        self::assertSame([AuditTrailInterface::PREFERENCES_CHANGED, '7', '7'], [$action, $actor, $subject]);
        self::assertSame('user', $context['recipient_type']);
        self::assertCount(PreferencesAuditListener::MAX_KEYS, $context['changed']);
        self::assertSame('account/issues/mail', $context['changed'][0]);
        self::assertSame([60, false], [$context['count'], $context['reset']]);
    }

    public function testAnOperatorActionHasNoSubject(): void
    {
        $recorder = $this->recorder();
        (new AuditKitAuditTrail($recorder))->record(AuditTrailInterface::RETENTION_RUN, null, ['read' => 3]);
        self::assertSame([AuditTrailInterface::RETENTION_RUN, null, null, ['read' => 3]], $recorder->calls[0]);
        (new NullAuditTrail())->record('x', null);
    }

    public function testThePassWiresTheBridgeOnlyWhenAuditKitIsThere(): void
    {
        $container = $this->container('auto');
        (new AuditKitBridgePass())->process($container);
        self::assertSame(NullAuditTrail::class, (string) $container->getAlias(AuditTrailInterface::class), 'auto without AuditKit');

        $container = $this->container('auto');
        $container->register(AuditKitBridgePass::RECORDER, 'stdClass');
        (new AuditKitBridgePass())->process($container);
        self::assertSame(AuditKitAuditTrail::class, (string) $container->getAlias(AuditTrailInterface::class));
        self::assertSame(AuditKitBridgePass::RECORDER, (string) $container->getDefinition(AuditKitAuditTrail::class)->getArgument(0));
        self::assertTrue($container->getDefinition(PreferencesAuditListener::class)->hasTag('kernel.event_listener'));

        $container = $this->container(true);
        $container->register(AuditKitBridgePass::RECORDER, 'stdClass');
        $container->setAlias(AuditKitBridgePass::RECORDER_PORT, AuditKitBridgePass::RECORDER);
        (new AuditKitBridgePass())->process($container);
        self::assertSame(AuditKitBridgePass::RECORDER_PORT, (string) $container->getDefinition(AuditKitAuditTrail::class)->getArgument(0), 'the port first');

        $container = $this->container(false);
        $container->register(AuditKitBridgePass::RECORDER, 'stdClass');
        (new AuditKitBridgePass())->process($container);
        self::assertSame(NullAuditTrail::class, (string) $container->getAlias(AuditTrailInterface::class), 'false never wires it');

        $bare = new ContainerBuilder();
        (new AuditKitBridgePass())->process($bare);
        self::assertFalse($bare->hasAlias(AuditTrailInterface::class), 'bundle disabled');

        $this->expectException(LogicException::class);
        (new AuditKitBridgePass())->process($this->container(true));
    }

    private function container(bool|string $mode): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_notify_kit.config', ['bridges' => ['audit_kit' => $mode]]);
        $container->register(NullAuditTrail::class, NullAuditTrail::class);
        $container->setAlias(AuditTrailInterface::class, NullAuditTrail::class);

        return $container;
    }

    /**
     * @return ActionLogIdRecorderInterface&object{calls: list<array{string, mixed, mixed, array<string, mixed>}>}
     */
    private function recorder(): ActionLogIdRecorderInterface
    {
        return new class($this->createStub(ActionLogEntryInterface::class)) implements ActionLogIdRecorderInterface {
            /** @var list<array{string, mixed, mixed, array<string, mixed>}> */
            public array $calls = [];

            public function __construct(private readonly ActionLogEntryInterface $entry)
            {
            }

            public function recordIds(string|BackedEnum $action, mixed $actorId = null, mixed $subjectId = null, array $context = [], ?string $scope = null): ActionLogEntryInterface
            {
                return $this->entry;
            }

            public function recordIdsAndFlush(string|BackedEnum $action, mixed $actorId = null, mixed $subjectId = null, array $context = [], ?string $scope = null): ActionLogEntryInterface
            {
                $this->calls[] = [(string) ($action instanceof BackedEnum ? $action->value : $action), $actorId, $subjectId, $context];

                return $this->entry;
            }
        };
    }
}
