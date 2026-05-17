<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Json;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Form for editing per-tenant step-level overrides in the Workflow Overlay Editor (Y.3).
 *
 * Editable keys:
 *   - approverRole               string|null  (ROLE_* override)
 *   - approverUsers              string|null  (JSON array of user IDs)
 *   - daysToComplete             int|null     (SLA days override)
 *   - autoProgressConditions     string|null  (JSON condition object)
 *   - reasonRequired             bool|null    (tri-state: null = use YAML)
 *   - fourEyes                   bool|null    (tri-state)
 *   - module                     string|null  (module-gate key)
 *
 * NULL means "use YAML baseline" — controller omits the row from lifecycle_config.
 */
final class WorkflowStepOverlayType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('approverRole', TextType::class, [
                'label' => 'admin.workflows.form.approver_role',
                'required' => false,
                'attr' => [
                    'placeholder' => 'ROLE_DPO',
                    'autocomplete' => 'off',
                ],
                'help' => 'admin.workflows.form.approver_role_help',
            ])
            ->add('approverUsers', TextType::class, [
                'label' => 'admin.workflows.form.approver_users',
                'required' => false,
                'attr' => [
                    'placeholder' => '[]',
                    'autocomplete' => 'off',
                ],
                'help' => 'admin.workflows.form.approver_users_help',
            ])
            ->add('daysToComplete', IntegerType::class, [
                'label' => 'admin.workflows.form.days_to_complete',
                'required' => false,
                'constraints' => [
                    new PositiveOrZero(message: 'admin.workflows.validation.days_non_negative'),
                ],
                'attr' => [
                    'min' => 0,
                    'placeholder' => '1',
                ],
                'help' => 'admin.workflows.form.days_to_complete_help',
            ])
            ->add('autoProgressConditions', TextareaType::class, [
                'label' => 'admin.workflows.form.auto_progress_conditions',
                'required' => false,
                'constraints' => [
                    new Json(message: 'admin.workflows.validation.conditions_invalid_json'),
                ],
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control font-monospace',
                    'placeholder' => '{"type": "field_completion", "fields": ["severity"]}',
                ],
                'help' => 'admin.workflows.form.auto_progress_conditions_help',
            ])
            ->add('reasonRequired', CheckboxType::class, [
                'label' => 'admin.workflows.form.reason_required',
                'required' => false,
            ])
            ->add('reasonRequiredOverride', CheckboxType::class, [
                'label' => 'admin.workflows.form.enable_override',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'workflow-overlay-toggle'],
            ])
            ->add('fourEyes', CheckboxType::class, [
                'label' => 'admin.workflows.form.four_eyes',
                'required' => false,
            ])
            ->add('fourEyesOverride', CheckboxType::class, [
                'label' => 'admin.workflows.form.enable_override',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'workflow-overlay-toggle'],
            ])
            ->add('module', TextType::class, [
                'label' => 'admin.workflows.form.module',
                'required' => false,
                'attr' => [
                    'placeholder' => 'privacy',
                    'autocomplete' => 'off',
                ],
                'help' => 'admin.workflows.form.module_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'admin',
        ]);
    }
}
