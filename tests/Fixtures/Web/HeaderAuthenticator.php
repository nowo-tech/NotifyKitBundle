<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Web;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

use function array_filter;
use function array_values;
use function explode;

/**
 * Test-host login: `X-Test-User: <id>` (roles from `X-Test-Roles`, comma-separated; default ROLE_USER). Anonymous
 * requests to a protected action get the entry point's 401.
 */
final class HeaderAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function supports(Request $request): bool
    {
        return $request->headers->has('X-Test-User');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $id    = (string) $request->headers->get('X-Test-User');
        $roles = array_values(array_filter(explode(',', (string) $request->headers->get('X-Test-Roles', 'ROLE_USER'))));

        return new SelfValidatingPassport(new UserBadge($id, static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, $roles)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return new JsonResponse(['error' => 'login'], 401);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return new JsonResponse(['error' => 'login'], 401);
    }
}
