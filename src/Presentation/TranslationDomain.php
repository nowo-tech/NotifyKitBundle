<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation;

/**
 * Translation domain of the bundle UI copy (REQ-I18N-003), kept in the UI layer so controllers, views and Twig code
 * read it without depending on the bundle class (REQ-ARCH-002). `NowoNotifyKitBundle::TRANSLATION_DOMAIN` is the same
 * value.
 */
final class TranslationDomain
{
    /** The domain name (also the Twig namespace, REQ-TWIG-002). */
    public const NAME = 'NowoNotifyKitBundle';
}
