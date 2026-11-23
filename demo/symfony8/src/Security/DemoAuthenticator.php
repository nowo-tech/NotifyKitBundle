<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\LogicException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Demo only: never authenticates a request by itself; the home page's "Sign in as" form calls Security::login() with
 * it. Anonymous visitors of a protected page are sent to the home page.
 */
final class DemoAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function supports(Request $request): bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new LogicException('The demo signs in through Security::login().');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('demo_home'));
    }
}
