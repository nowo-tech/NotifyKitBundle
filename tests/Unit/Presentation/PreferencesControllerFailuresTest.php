<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Presentation;

use Nowo\NotifyKitBundle\Application\Preferences\PreferenceDefaults;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Presentation\Controller\PreferencesController;
use Nowo\NotifyKitBundle\Presentation\Form\PreferencesType;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Nowo\NotifyKitBundle\Presentation\Security\RoleAccessChecker;
use Nowo\NotifyKitBundle\Presentation\View\PreferencePages;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectContextProvider;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\WebRecipientResolver;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function json_decode;

/**
 * Failure answers of the preferences endpoints a live host can hardly produce on demand: lock conflicts on save and
 * reset (409 + CONFLICT_001; JSON with Retry-After for the autosave, the host's error page for forms), a save refused
 * by the service, a tampered token in a no-JS form, the form without scope selects, and the form type without a grid.
 */
final class PreferencesControllerFailuresTest extends TestCase
{
    public function testTheAutosaveAnswers409OnALockConflict(): void
    {
        $answer = $this->controller(new TransientStorageFailure(3))->cells(Request::create('/cells', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'ok'], content: '{"changes":[{"context":"","category":"general","channel":"mail","enabled":false,"scope":"all","expected":"-"}]}'));
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('3', $answer->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', json_decode((string) $answer->getContent(), true)['code']);
    }

    public function testTheResetAnswers409OnALockConflict(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('CONFLICT_001');
        $this->controller(new TransientStorageFailure(1))->reset($this->form('/context/reset?key=project:42', ['_token' => 'ok', '_redirect' => '/x']));
    }

    public function testTheNoJsFormAnswersLockConflictsAndRefusals(): void
    {
        try {
            $this->controller(new TransientStorageFailure(1))->save($this->master('-'));
            self::fail('409');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        try {
            $this->controller(new InvalidNotificationArgumentException('no'))->save($this->master('-'));
            self::fail('refused');
        } catch (AccessDeniedHttpException) {
        }
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Invalid preferences form.');
        $this->controller(null)->save($this->master('tampered'));
    }

    public function testAFormWithoutScopeSelectsSavesTheScopeAll(): void
    {
        $preferences = $this->preferences(null);
        $controller  = $this->controller(null, ['all'], $preferences);
        $request     = $this->form('/', ['nkp' => ['_token' => 'ok', 'w' => '1', 'kw' => '-', 'vw' => '1', 'c0' => '1', 'kc0' => '-', 'vc0' => '0']]);
        self::assertSame(302, $controller->save($request)->getStatusCode());
    }

    public function testTheFormTypeWithoutAGridHasNoField(): void
    {
        self::assertCount(1, $this->forms()->create(PreferencesType::class)->createView()->children, 'the CSRF token only');
    }

    /**
     * A no-JS post that turns the master switch off, with the page's token $token.
     */
    private function master(string $token): Request
    {
        return $this->form('/', ['nkp' => ['_token' => 'ok', 'kw' => $token, 'vw' => '1', 'vc0' => '1', 'kc0' => '-', 'c0' => '1']]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function form(string $uri, array $parameters): Request
    {
        $request = Request::create($uri, 'POST', $parameters);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @param list<string> $scopes
     */
    private function controller(?Throwable $failure, array $scopes = ['all', 'involved'], ?PreferencesInterface $preferences = null): PreferencesController
    {
        $registry = $this->registry();
        $user     = new InMemoryUser('alice', null, ['ROLE_USER']);
        $tokens   = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/prefs');

        return new PreferencesController(
            $preferences ?? $this->preferences($failure),
            new ProjectContextProvider(['user:alice' => ['42' => 'Apollo']]),
            new CurrentRecipient($tokens, new WebRecipientResolver()),
            new RoleAccessChecker(null, []),
            new PreferencePages($registry, new Translator('en'), $scopes),
            $this->forms(),
            new Environment(new ArrayLoader()),
            $router,
            $this->csrf(),
            $scopes,
        );
    }

    private function preferences(?Throwable $failure): PreferencesInterface
    {
        $preferences = $this->createStub(PreferencesInterface::class);
        $preferences->method('matrixFor')->willReturn(PreferenceDefaults::of($this->registry())->matrix(new RecipientRef('user', 'alice'), []));
        if ($failure instanceof Throwable) {
            $preferences->method('save')->willThrowException($failure);
            $preferences->method('resetContext')->willThrowException($failure);
        } else {
            $preferences->method('save')->willReturn([]);
        }

        return $preferences;
    }

    private function registry(): TypeRegistry
    {
        return TypeRegistry::create(['billing.ready' => ['category' => 'general', 'icon' => null, 'title_key' => null, 'permission' => null, 'channels' => ['in_app' => true, 'mail' => true], 'in_app_optional' => false, 'locked_channels' => [], 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => true]], [], [], false, null);
    }

    private function forms(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()->addExtension(new CsrfExtension($this->csrf()))->addExtension(new HttpFoundationExtension())->getFormFactory();
    }

    private function csrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $csrf->method('getToken')->willReturn(new CsrfToken('x', 'ok'));

        return $csrf;
    }
}
