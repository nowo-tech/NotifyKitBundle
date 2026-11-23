<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Http;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The answer of the bundle's own endpoints to a lock conflict (REQ-RUNTIME-003 rule 6): **409** with the
 * `ConflictCode` of the failure (`CONFLICT_001` + `Retry-After` when nothing was committed). Every bundle write is
 * idempotent (`UPDATE … WHERE read_at IS [NOT] NULL`, chunked deletes), so a retry is always safe.
 *
 * @internal
 */
final class Conflicts
{
    /**
     * JSON answer for `fetch` callers: `{"ok": false, "error": "conflict", "code": "CONFLICT_00n"}`.
     */
    public static function json(TransientStorageFailure $failure): JsonResponse
    {
        $code = ConflictCode::forFailure($failure);

        return Responses::noStore(new JsonResponse(['ok' => false, 'error' => 'conflict', 'code' => $code->value], 409, self::headers($failure, $code)));
    }

    /**
     * The same as an HTTP exception (the host's error page) for plain form posts.
     */
    public static function exception(TransientStorageFailure $failure): HttpException
    {
        $code = ConflictCode::forFailure($failure);

        return new HttpException(409, $code->value, $failure, self::headers($failure, $code));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(TransientStorageFailure $failure, ConflictCode $code): array
    {
        return $code->retrySafe() ? ['Retry-After' => (string) ($failure->retryAfterSeconds() ?? 1)] : [];
    }
}
