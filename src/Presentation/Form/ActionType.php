<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only form of the no-JS state changes (mark read / unread, mark all as read, run retention; REQ-TWIG-005: a Form
 * Type rendered by UiKit's action partial, never a raw `<form>`). POST, field `_token`, `csrf_token_id` required
 * (`nowo_notify_kit_inbox` or `nowo_notify_kit_operator`). The hidden `_redirect` (a local path, checked again on
 * submit) brings the person back to the page they acted on; it also makes the nameless form "submitted".
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('notify_kit')]
final class ActionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the hidden return path.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('_redirect', HiddenType::class, ['mapped' => false, 'data' => $options['redirect']]);
    }

    /**
     * POST, CSRF on, `csrf_token_id` required, `redirect` = the return path.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
            'redirect'           => '',
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('redirect', 'string');
    }

    /**
     * Nameless: the fields are `_token` and `_redirect`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
