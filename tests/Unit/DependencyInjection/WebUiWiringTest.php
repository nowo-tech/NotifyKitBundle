<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\Contract\Security\InboxAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\Security\OperatorAccessCheckerInterface;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\RealtimePass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\WebUiPass;
use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Nowo\NotifyKitBundle\Infrastructure\Realtime\MercureRealtimePublisher;
use Nowo\NotifyKitBundle\Infrastructure\Realtime\MercureSubscription;
use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Controller\OperatorController;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mercure\HubInterface;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Wiring of the read side and the pages: host access checkers, the realtime pass, the host-requirements pass, the
 * Twig namespaces and the FormKit profile.
 */
final class WebUiWiringTest extends TestCase
{
    public function testTheInboxNeedsToKnowWhoIsLoggedIn(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('recipient.class');
        (new NowoNotifyKitExtension())->load([['enabled' => true, 'web_ui' => ['enabled' => true]]], $this->container());
    }

    public function testHostAccessCheckersAndPublishersAreAliased(): void
    {
        $container = $this->container();
        (new NowoNotifyKitExtension())->load([[
            'enabled'   => true,
            'recipient' => ['class' => User::class],
            'realtime'  => ['publisher' => 'app.publisher'],
            'web_ui'    => ['enabled' => true, 'security' => ['access_checker' => 'app.inbox_access']],
            'admin_ui'  => ['enabled' => true, 'security' => ['access_checker' => 'app.operator_access']],
        ]], $container);

        self::assertSame('app.inbox_access', (string) $container->getAlias(InboxAccessCheckerInterface::class));
        self::assertSame('app.operator_access', (string) $container->getAlias(OperatorAccessCheckerInterface::class));
        self::assertSame('app.publisher', (string) $container->getAlias(RealtimePublisherInterface::class));
        self::assertSame('host', $container->getParameter(NowoNotifyKitExtension::REALTIME_MODE));
        self::assertFalse($container->hasDefinition('nowo_notify_kit.inbox_access_checker'));
    }

    public function testTheDefaultCheckersHaveTheirOwnRoles(): void
    {
        $container = $this->container();
        (new NowoNotifyKitExtension())->load([[
            'enabled'   => true,
            'recipient' => ['class' => User::class],
            'realtime'  => ['mode' => 'off'],
            'web_ui'    => ['enabled' => true, 'security' => ['access_roles' => []]],
            'admin_ui'  => ['enabled' => true, 'security' => ['allow_unauthenticated' => true]],
        ]], $container);

        self::assertSame([], $container->getDefinition('nowo_notify_kit.inbox_access_checker')->getArgument(1));
        self::assertSame(['ROLE_ADMIN'], $container->getDefinition('nowo_notify_kit.operator_access_checker')->getArgument(1));
        self::assertTrue($container->getDefinition('nowo_notify_kit.operator_access_checker')->getArgument(2));
        self::assertSame('off', $container->getParameter(NowoNotifyKitExtension::REALTIME_MODE));
        self::assertFalse($container->hasDefinition(MercureRealtimePublisher::class));
    }

    public function testTheRealtimePassKeepsMercureOnlyWithAHub(): void
    {
        $container = $this->realtime('auto');
        $container->register(HubInterface::class, HubInterface::class);
        (new RealtimePass())->process($container);
        self::assertTrue($container->hasDefinition(MercureRealtimePublisher::class));
        self::assertSame('mercure', $container->getParameter(NowoNotifyKitExtension::REALTIME_MODE));

        $container = $this->realtime('auto');
        (new RealtimePass())->process($container);
        self::assertFalse($container->hasDefinition(MercureRealtimePublisher::class));
        self::assertFalse($container->hasDefinition(MercureSubscription::class));
        self::assertFalse($container->hasAlias(RealtimePublisherInterface::class));
        self::assertSame('poll', $container->getParameter(NowoNotifyKitExtension::REALTIME_MODE));

        $container = new ContainerBuilder();
        (new RealtimePass())->process($container);
        self::assertFalse($container->hasParameter(NowoNotifyKitExtension::REALTIME_MODE), 'bundle disabled');

        $container = new ContainerBuilder();
        $container->setParameter(NowoNotifyKitExtension::REALTIME_REQUESTED, 'mercure');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('symfony/mercure');
        (new RealtimePass())->process($container);
    }

    public function testThePagesNeedTheirHostPieces(): void
    {
        (new WebUiPass())->process(new ContainerBuilder());

        $container = new ContainerBuilder();
        $container->setParameter('nowo_notify_kit.config', ['admin_ui' => ['security' => ['allow_unauthenticated' => true]]]);
        $container->setDefinition(OperatorController::class, new Definition(OperatorController::class));
        foreach (['twig', 'form.factory', 'security.csrf.token_manager', 'router', 'translator'] as $id) {
            $container->setDefinition($id, new Definition('stdClass'));
        }
        (new WebUiPass())->process($container);
        $this->addToAssertionCount(1); // the operator page of a demo runs without SecurityBundle

        $container->setDefinition(InboxController::class, new Definition(InboxController::class));
        $container->removeDefinition('twig');
        try {
            (new WebUiPass())->process($container);
            self::fail('the inbox needs Twig and SecurityBundle');
        } catch (LogicException $exception) {
            self::assertStringContainsString('TwigBundle', $exception->getMessage());
            self::assertStringContainsString('SecurityBundle', $exception->getMessage());
        }
    }

    public function testTwigNamespacesPutTheHostOverridesFirst(): void
    {
        $project = sys_get_temp_dir() . '/nowo-notify-kit-twig-' . uniqid();
        (new Filesystem())->mkdir($project . '/templates/bundles/NowoNotifyKitBundle');
        $container = new ContainerBuilder();
        (new TwigPathsPass())->process($container);
        $container->setParameter('kernel.project_dir', $project);
        $loader = $container->register('twig.loader.native_filesystem', 'Twig\Loader\FilesystemLoader');
        (new TwigPathsPass())->process($container);
        $calls = $loader->getMethodCalls();
        (new Filesystem())->remove($project);

        self::assertSame(['prependPath', [$project . '/templates/bundles/NowoNotifyKitBundle', 'NowoNotifyKitBundle']], $calls[0]);
        self::assertSame('NowoNotifyKitBundle', $calls[1][1][1]);
        self::assertSame('!NowoNotifyKitBundle', $calls[2][1][1]);
    }

    public function testPrependAddsTheAssetPackageAndKeepsAHostFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        foreach (['framework', 'nowo_form_kit'] as $alias) {
            $container->registerExtension($this->extension($alias));
        }
        (new NowoNotifyKitExtension())->prepend($container);
        self::assertSame('/bundles/nowonotifykit', $container->getExtensionConfig('framework')[0]['assets']['packages']['nowo_notify_kit']['base_path']);
        self::assertSame('NowoNotifyKitBundle', $container->getExtensionConfig('nowo_form_kit')[0]['profiles']['notify_kit']['translation_domain']);

        $host = new ContainerBuilder();
        $host->registerExtension($this->extension('nowo_form_kit'));
        $host->prependExtensionConfig('nowo_form_kit', ['profiles' => ['notify_kit' => ['alias' => 'mine']]]);
        (new NowoNotifyKitExtension())->prepend($host);
        self::assertCount(1, $host->getExtensionConfig('nowo_form_kit'), 'the host profile wins');
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        return $container;
    }

    private function realtime(string $mode): ContainerBuilder
    {
        $container = $this->container();
        (new NowoNotifyKitExtension())->load([['enabled' => true, 'realtime' => ['mode' => $mode]]], $container);

        return $container;
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
