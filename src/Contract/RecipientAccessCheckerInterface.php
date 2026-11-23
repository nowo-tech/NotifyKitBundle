<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Application\Default\PersonalOnlyAccessChecker;

/**
 * Host SPI (`access.checker`): may this person open targets in a context? Deny by default (contract §6.1): a row with
 * a context is written only when the checker grants the type's permission there. The default,
 * {@see PersonalOnlyAccessChecker}, grants nothing, so only personal rows
 * (no context) are written. The same checker filters the inbox at read time (S2): it is asked once per permission
 * with every context of the recipient's rows, so a lost permission hides the rows at once.
 *
 * Implementations are stateless or reset per request (worker mode): they are asked once per recipient and request.
 */
interface RecipientAccessCheckerInterface
{
    /**
     * Whether the recipient sees every context (an instance administrator, for example).
     */
    public function isUnrestricted(RecipientRef $recipient): bool;

    /**
     * The candidates in which the recipient holds $permission. Anything else returned is ignored.
     *
     * @param list<ContextRef> $candidates
     *
     * @return list<ContextRef>
     */
    public function contextsGranting(RecipientRef $recipient, string $permission, array $candidates): array;
}
