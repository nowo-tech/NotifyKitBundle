<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Presentation\TranslationDomain;
use Symfony\Contracts\Translation\TranslatorInterface;

use function intdiv;
use function max;

/**
 * Coarse relative time of a row ("just now", "5 min ago", "3 h ago", "2 d ago", then the date), translated in the
 * bundle domain. The exact time stays in the `<time datetime>` attribute and its title.
 *
 * @internal
 */
final readonly class RelativeTime
{
    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * The label of $at seen at $now.
     */
    public function label(DateTimeImmutable $at, DateTimeImmutable $now, ?string $locale = null): string
    {
        $seconds       = max(0, $now->getTimestamp() - $at->getTimestamp());
        [$key, $count] = match (true) {
            $seconds < 60     => ['notify.time.just_now', 0],
            $seconds < 3600   => ['notify.time.minutes', intdiv($seconds, 60)],
            $seconds < 86400  => ['notify.time.hours', intdiv($seconds, 3600)],
            $seconds < 604800 => ['notify.time.days', intdiv($seconds, 86400)],
            default           => [null, 0],
        };
        if ($key === null) {
            return $at->format('Y-m-d');
        }

        return $this->translator->trans($key, ['%count%' => $count], TranslationDomain::NAME, $locale);
    }
}
