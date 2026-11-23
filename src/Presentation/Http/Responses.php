<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Http;

use Nowo\NotifyKitBundle\Contract\Guard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;
use function rawurldecode;

/**
 * HTTP hygiene of the bundle endpoints (contract §6.2, §6.3).
 *
 * @internal
 */
final class Responses
{
    /** Header the bell's `fetch` calls send the CSRF token in. */
    public const CSRF_HEADER = 'X-CSRF-TOKEN';

    /**
     * `Cache-Control: no-store, private` (bell, count, inbox and every JSON answer).
     *
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    public static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Whether $request is a `fetch` call of the bell (it carries the CSRF header): answered in JSON.
     */
    public static function isFetch(Request $request): bool
    {
        return $request->headers->has(self::CSRF_HEADER);
    }

    /**
     * The `_redirect` field of a no-JS form when it is a local path, before and after URL-decoding (open-redirect
     * guard, audit QL-I1); otherwise $fallback.
     */
    public static function redirectTarget(Request $request, string $fallback): string
    {
        $target = $request->request->all()['_redirect'] ?? null;

        return is_string($target) && Guard::isLocalPath($target) && Guard::isLocalPath(rawurldecode($target)) ? $target : $fallback;
    }
}
