<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Exception;

use InvalidArgumentException;

/**
 * A value object or a request was built with a value the contract refuses (a key that does not match its pattern, an
 * identifier that is empty, too long or holds control characters, a URL that is not a local path, too many params…).
 * The message names the field and the rule, never the refused value.
 */
final class InvalidNotificationArgumentException extends InvalidArgumentException
{
}
