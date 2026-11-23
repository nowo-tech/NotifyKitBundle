<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Presentation\View\GridSwitch;
use Nowo\NotifyKitBundle\Presentation\View\PreferenceGrid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The preferences matrix form (contract §8.3, REQ-TWIG-005): one checkbox per unlocked category × channel cell
 * (`c<n>`), one scope select per category (`s<n>`), the master switch (`w`) and the context switches (`x<n>`), and a
 * hidden token per checkbox
 * (`k<name>`: the stored state the page showed, {@see PreferenceExpectation::tokenOf()})
 * and the value the page showed per checkbox and scope select (`v<name>`).
 * Without JavaScript the form posts everything and only the cells that differ from what the page showed are saved;
 * with the preferences script each change is saved on its own (autosave). The grid (option `grid`) decides the fields.
 * POST, CSRF `nowo_notify_kit_preferences`.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('notify_kit')]
final class PreferencesType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id of every preferences POST. */
    public const CSRF_ID = 'nowo_notify_kit_preferences';

    /**
     * Adds the fields of the grid.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $grid = $options['grid'];
        if (!$grid instanceof PreferenceGrid) {
            return;
        }
        foreach ([...($grid->switch instanceof GridSwitch ? [$grid->switch] : []), ...$grid->contexts] as $switch) {
            $builder->add($switch->field, CheckboxType::class, [
                'label'              => $switch->label,
                'translation_domain' => $switch->contextKey === '' ? 'NowoNotifyKitBundle' : false,
                'required'           => false,
                'data'               => $switch->checked,
            ]);
            $builder->add('k' . $switch->field, HiddenType::class, ['data' => $switch->token]);
            $builder->add('v' . $switch->field, HiddenType::class, ['data' => $switch->checked ? '1' : '0']);
        }
        foreach ($grid->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->field === null) {
                    continue;
                }
                $builder->add($cell->field, CheckboxType::class, ['label' => $cell->label, 'translation_domain' => false, 'required' => false, 'data' => $cell->checked]);
                $builder->add('k' . $cell->field, HiddenType::class, ['data' => $cell->token]);
                $builder->add('v' . $cell->field, HiddenType::class, ['data' => $cell->checked ? '1' : '0']);
            }
            if ($row->scopeField !== null) {
                $builder->add($row->scopeField, ChoiceType::class, [
                    'label'    => 'notify.preferences.scope',
                    'required' => true,
                    'choices'  => ['notify.preferences.scope_all' => Scope::All->value, 'notify.preferences.scope_involved' => Scope::Involved->value],
                    'data'     => $row->scope,
                ]);
                $builder->add('v' . $row->scopeField, HiddenType::class, ['data' => $row->scope]);
            }
        }
    }

    /**
     * POST, CSRF on, option `grid`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_ID,
            'translation_domain' => 'NowoNotifyKitBundle',
            'grid'               => null,
        ]);
        $resolver->setAllowedTypes('grid', ['null', PreferenceGrid::class]);
    }
}
