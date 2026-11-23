<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Title;

use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationView;
use Nowo\NotifyKitBundle\Contract\RecipientDirectoryInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function preg_replace;

/**
 * Display text of a row, built at display time in the viewer's language (contract §4, §6.2; audit QL-I1):
 *
 * - title: the type's `title_key` in its `title_domain` (unknown types: the generic title of the bundle), with each
 *   param as `%<key>%`, then the built-ins `%actor%` (the actor's display name, "Someone" when unknown or erased),
 *   `%target%` and `%repeats%` applied **after** the params, so a param can never spoof them;
 * - CR / LF and other control characters are folded to spaces (one line);
 * - the result is plain text: templates print it autoescaped (never `|raw`) inside `<bdi>` / `dir="auto"`.
 *
 * Stateless (worker mode): every value comes from the call.
 */
final readonly class NotificationTitles
{
    private const DOMAIN = NowoNotifyKitBundle::TRANSLATION_DOMAIN;

    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(
        private TranslatorInterface $translator,
        private TypeRegistryInterface $types,
        private RecipientDirectoryInterface $directory,
        private ContextProviderInterface $contexts,
    ) {
    }

    /**
     * The title of $notification in $locale (null = the translator's locale).
     */
    public function title(NotificationView $notification, ?string $locale = null): string
    {
        $type       = $this->types->type($notification->type);
        $known      = $this->types->has($notification->type);
        $parameters = [];
        foreach ($notification->params as $key => $value) {
            $parameters['%' . $key . '%'] = $value;
        }
        $parameters['%actor%']   = $this->actorName($notification->actor, $locale);
        $parameters['%target%']  = $notification->target;
        $parameters['%repeats%'] = (string) $notification->repeats;

        $title = $known
            ? $this->translator->trans($type->titleKey, $parameters, $type->titleDomain ?? 'messages', $locale)
            : $this->translator->trans(TypeRegistry::GENERIC_TITLE_KEY, $parameters, self::DOMAIN, $locale);

        return self::oneLine($title);
    }

    /**
     * The actor's display name, or "Someone".
     */
    public function actorName(?RecipientRef $actor, ?string $locale = null): string
    {
        $name = $actor instanceof RecipientRef ? $this->directory->displayName($actor) : null;

        return $name !== null ? self::oneLine($name) : $this->translator->trans('notify.actor.someone', [], self::DOMAIN, $locale);
    }

    /**
     * The label of a context, or null.
     */
    public function contextLabel(?ContextRef $context): ?string
    {
        $label = $context instanceof ContextRef ? $this->contexts->label($context) : null;

        return $label === null ? null : self::oneLine($label);
    }

    /**
     * $text on one line: control characters (CR, LF, tab…) become spaces.
     */
    public static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    }
}
