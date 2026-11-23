<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Presentation;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Application\Default\NoContextProvider;
use Nowo\NotifyKitBundle\Application\Default\ResolverRecipientDirectory;
use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Controller\OperatorController;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Nowo\NotifyKitBundle\Presentation\Security\RoleAccessChecker;
use Nowo\NotifyKitBundle\Presentation\Title\NotificationTitles;
use Nowo\NotifyKitBundle\Presentation\View\RelativeTime;
use Nowo\NotifyKitBundle\Tests\Fixtures\Sqlite;
use Nowo\NotifyKitBundle\Tests\Fixtures\SteppingClock;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\WebRecipientResolver;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function json_decode;
use function json_encode;

/**
 * Failure answers of the controllers that a live host can hardly produce on demand: lock conflicts (409 +
 * CONFLICT_001, JSON for `fetch`, the host's error page for forms), a malformed id that bypassed the route
 * requirement (404), and a retention pass that ran out of time with and without a message bus.
 */
final class ControllerFailuresTest extends TestCase
{
    public function testInboxConflictsAnswer409AndAMalformedIdIs404(): void
    {
        $inbox = $this->createStub(InboxInterface::class);
        $inbox->method('markAllRead')->willThrowException(new TransientStorageFailure(2));
        $inbox->method('markRead')->willThrowException(new TransientStorageFailure());
        $controller = $this->inboxController($inbox);

        $answer = $controller->readAll($this->fetch('/notifications/read-all'));
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('2', $answer->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', json_decode((string) $answer->getContent(), true)['code']);
        self::assertSame(409, $controller->read($this->fetch('/x'), '019a0000-0000-7000-8000-000000000001')->getStatusCode());

        try {
            $controller->readAll($this->form());
            self::fail('a form post gets the error page');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame('2', $exception->getHeaders()['Retry-After']);
        }

        $this->expectException(NotFoundHttpException::class);
        $controller->unread($this->fetch('/x'), 'NOT-A-UUID');
    }

    public function testRetentionOutOfTimeIsQueuedOrReportedAndAConflictIs409(): void
    {
        $connection = Sqlite::connection();
        $store      = new DbalNotificationStore($this->transactions($connection));
        foreach (['a', 'b', 'c'] as $n => $who) {
            foreach ([1, 2] as $i) {
                $connection->insert('nowo_notify_notification', [
                    'id'     => '019a0000-0000-7000-8000-0000000000' . $n . $i, 'recipient_type' => 'user', 'recipient_id' => $who, 'type' => 't', 'category' => 'general',
                    'target' => 'x', 'url' => '/x', 'params' => '{}', 'repeats' => 1, 'first_created_at' => '2026-11-15 10:00:00', 'created_at' => '2026-11-15 10:00:0' . $i,
                ]);
            }
        }
        $retention = new Retention($store, $this->transactions($connection), 90, 1, 1, 1, new SteppingClock('2026-11-15 10:00:00', 5));
        $bus       = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };

        $request = $this->form();
        self::assertSame('/admin', $this->operator($store, $retention, $bus)->retention($request)->getTargetUrl());
        self::assertCount(1, $bus->sent);
        self::assertInstanceOf(PruneNotificationsMessage::class, $bus->sent[0]);
        self::assertStringContainsString('notify.operator.retention_queued', $this->flashes($request));

        $request = $this->form();
        $this->operator($store, $retention, null)->retention($request);
        self::assertStringContainsString('notify.operator.retention_partial', $this->flashes($request));

        $failing = $this->createStub(NotificationStoreInterface::class);
        $failing->method('deleteReadBefore')->willThrowException(new TransientStorageFailure(1));
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('CONFLICT_001');
        $this->operator($store, new Retention($failing, $this->transactions($connection)), null)->retention($this->form());
    }

    private function flashes(Request $request): string
    {
        $session = $request->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return (string) json_encode($session->getFlashBag()->peekAll());
    }

    private function inboxController(InboxInterface $inbox): InboxController
    {
        $translator = new Translator('en');
        $types      = TypeRegistry::create([], [], [], false, null);

        return new InboxController(
            $inbox,
            $types,
            $this->current(),
            new RoleAccessChecker(null, []),
            new NotificationTitles($translator, $types, new ResolverRecipientDirectory(), new NoContextProvider()),
            new RelativeTime($translator),
            $this->forms(),
            new Environment(new ArrayLoader()),
            $this->router(),
            $this->csrf(),
        );
    }

    private function operator(NotificationStoreInterface $store, Retention $retention, ?MessageBusInterface $bus): OperatorController
    {
        return new OperatorController($store, $retention, $this->current(), new RoleAccessChecker(null, []), $this->forms(), new Environment(new ArrayLoader()), $this->router(), new Translator('en'), $bus);
    }

    private function current(): CurrentRecipient
    {
        $user   = new InMemoryUser('alice', null, ['ROLE_USER']);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return new CurrentRecipient($tokens, new WebRecipientResolver());
    }

    private function forms(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()->addExtension(new CsrfExtension($this->csrf()))->addExtension(new HttpFoundationExtension())->getFormFactory();
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        return $csrf;
    }

    private function router(): UrlGeneratorInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/admin');

        return $router;
    }

    private function fetch(string $uri): Request
    {
        return Request::create($uri, 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'ok']);
    }

    private function form(): Request
    {
        $request = Request::create('/x', 'POST', ['_token' => 'ok', '_redirect' => '/admin']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function transactions(Connection $connection): NotifyKitTransactions
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getConnection')->willReturn($connection);

        return new NotifyKitTransactions($registry, new AfterCommitQueue());
    }
}
