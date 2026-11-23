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
 * "Sign in as" (demo people only), REQ-DEMO-015: a FormKit form.
 *
 * @extends AbstractType<array{person: string}>
 */
final class SignInType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('person', ChoiceType::class, [
                'label'    => 'Person',
                'help'     => false,
                'choices'  => ['Alice (operator, project "demo")' => 'alice', 'Bob' => 'bob'],
                'expanded' => true,
            ]);
        });
        $builder->add('submit', SubmitType::class, ['label' => 'Sign in', 'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => false, 'csrf_token_id' => 'demo_sign_in']);
    }
}
