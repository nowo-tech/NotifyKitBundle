<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Send a notification" (REQ-DEMO-015): what the demo producer sends, and to whom.
 *
 * @extends AbstractType<array{kind: string}>
 */
final class NotifyType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('kind', ChoiceType::class, [
                'label'   => 'Notification',
                'help'    => false,
                'choices' => [
                    'A personal welcome, to me (from the other person)' => 'welcome',
                    'A project "demo" update by Carol, to Alice and Bob (only Alice may open it)' => 'project',
                    'A mention of me by the other person (the bell row follows my preferences)' => 'mention',
                ],
            ]);
        });
        $builder->add('submit', SubmitType::class, ['label' => 'Send', 'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => false, 'csrf_token_id' => 'demo_notify']);
    }
}
