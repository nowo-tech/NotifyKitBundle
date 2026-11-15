<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function array_is_list;
use function count;
use function is_string;
use function mb_check_encoding;
use function mb_substr;
use function sprintf;

/**
 * One call to {@see NotifierInterface::notify()}: a type, the people to notify and what the notification points at
 * (contract §4.1). Built once and validated here, so an invalid request fails where it is written.
 *
 * Params are rendered into the title in the viewer's language at display time: never put translated text or a third
 * person's personal data in them (the actor is a reference resolved at display time, so erasing the actor removes the
 * name everywhere).
 */
final readonly class NotificationRequest
{
    /** Most params per request. */
    public const MAX_PARAMS = 20;

    /** Longest param value, in characters (longer values are cut). */
    public const MAX_PARAM_LENGTH = 200;

    /** Longest target key, in characters. */
    public const MAX_TARGET_LENGTH = 128;

    /** Longest dedupe key, in characters. */
    public const MAX_DEDUPE_KEY_LENGTH = 191;

    /** @var array<string, string> */
    public array $params;

    /**
     * @param string $type the type key (registered, or an open-set key that gets the generic definition)
     * @param list<RecipientRef> $recipients at most `fan_out.max_recipients` (the producer splits larger lists)
     * @param RecipientRef|null $actor who acted; never notified
     * @param ContextRef|null $context what the notification belongs to (a project…); null = personal
     * @param string $target groups repeats, e.g. `issue:<uuid>` (1–128 characters)
     * @param string $url a local path (`/projects/…`): no scheme, no `//host`, no backslash, at most 500 bytes
     * @param array<string, string> $params at most 20 keys (1–64 characters); each value UTF-8, at most 200 characters (longer values are cut)
     * @param Urgency $urgency from S4: may bypass quiet hours
     * @param list<RecipientRef> $involved from S3: the people the scope "involved" lets through (assignee, mentioned…)
     * @param string|null $dedupeKey drops a later request with the same key for 24 hours (once-per-state notices)
     */
    public function __construct(
        public string $type,
        public array $recipients,
        public ?RecipientRef $actor,
        public ?ContextRef $context,
        public string $target,
        public string $url,
        array $params = [],
        public Urgency $urgency = Urgency::Normal,
        public array $involved = [],
        public ?string $dedupeKey = null,
    ) {
        Guard::type('NotificationRequest type', $type);
        $this->refs('recipients', $recipients);
        $this->refs('involved', $involved);
        Guard::identifier('NotificationRequest target', $target, self::MAX_TARGET_LENGTH);
        if (!Guard::isLocalPath($url)) {
            throw new InvalidNotificationArgumentException('NotificationRequest url must be a local path: it starts with "/", not "//", has no backslash or control characters and is at most 500 bytes.');
        }
        if ($dedupeKey !== null) {
            Guard::identifier('NotificationRequest dedupeKey', $dedupeKey, self::MAX_DEDUPE_KEY_LENGTH);
        }
        $this->params = $this->params($params);
    }

    /**
     * @param array<mixed> $refs
     */
    private function refs(string $field, array $refs): void
    {
        if (!array_is_list($refs)) {
            throw new InvalidNotificationArgumentException(sprintf('NotificationRequest %s must be a list.', $field));
        }
        foreach ($refs as $ref) {
            if (!$ref instanceof RecipientRef) {
                throw new InvalidNotificationArgumentException(sprintf('NotificationRequest %s must hold RecipientRef objects only.', $field));
            }
        }
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, string>
     */
    private function params(array $params): array
    {
        if (count($params) > self::MAX_PARAMS) {
            throw new InvalidNotificationArgumentException(sprintf('NotificationRequest params hold at most %d keys.', self::MAX_PARAMS));
        }
        $clean = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidNotificationArgumentException('NotificationRequest params map string keys to string values.');
            }
            Guard::identifier('NotificationRequest param key', $key, 64);
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidNotificationArgumentException('NotificationRequest param values must be UTF-8.');
            }
            $clean[$key] = mb_substr($value, 0, self::MAX_PARAM_LENGTH, 'UTF-8');
        }

        return $clean;
    }
}
