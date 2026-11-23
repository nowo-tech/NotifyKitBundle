<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\NotifyKitBundle\Contract\InboxStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET filters of the inbox page (contract §8.2, REQ-TWIG-005): type, category and status. Nameless, so the query keys
 * stay `type` / `category` / `status` (bookmarkable); the keyset cursor `after` travels as an extra field. The choices
 * are the registry's known types and categories (option `types`: key => label, `categories`: label key => key).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('notify_kit')]
final class InboxFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void {
            $this->addTypedField('type', ChoiceType::class, [
                'label'                     => 'notify.filter.type',
                'help'                      => false,
                'required'                  => false,
                'placeholder'               => 'notify.filter.any_type',
                'choices'                   => $options['types'],
                'choice_translation_domain' => false,
            ]);
            $this->addTypedField('category', ChoiceType::class, [
                'label'       => 'notify.filter.category',
                'help'        => false,
                'required'    => false,
                'placeholder' => 'notify.filter.any_category',
                'choices'     => $options['categories'],
            ]);
            $this->addTypedField('status', ChoiceType::class, [
                'label'       => 'notify.filter.status',
                'help'        => false,
                'required'    => false,
                'placeholder' => 'notify.filter.any_status',
                'choices'     => [
                    'notify.status.unread' => InboxStatus::Unread->value,
                    'notify.status.read'   => InboxStatus::Read->value,
                ],
            ]);
        });
    }

    /**
     * GET, no CSRF, extra query keys allowed (cursor); `types` and `categories` choices.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => 'NowoNotifyKitBundle',
            'types'              => [],
            'categories'         => [],
        ]);
        $resolver->setAllowedTypes('types', 'array');
        $resolver->setAllowedTypes('categories', 'array');
    }

    /**
     * Nameless: query keys `type`, `category`, `status`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
