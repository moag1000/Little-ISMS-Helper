<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\SectionMapInterface;
use App\Form\Type\JsonBuilderType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Form for editing per-tenant step-level overrides in the Workflow Overlay Editor (Y.3).
 *
 * Editable keys:
 *   - approverRole               string|null  (ROLE_* override)
 *   - approverUsers              list<int>    (user IDs of the tenant — `user_choices`)
 *   - daysToComplete             int|null     (SLA days override)
 *   - autoProgressConditions     array|null   (condition object, structured builder;
 *                                              same keys as the YAML auto_progress_conditions)
 *   - reasonRequired             bool|null    (tri-state: null = use YAML)
 *   - fourEyes                   bool|null    (tri-state)
 *   - module                     string|null  (module-gate key)
 *
 * NULL means "use YAML baseline" — controller omits the row from lifecycle_config.
 */
final class WorkflowStepOverlayType extends AbstractType implements SectionMapInterface
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
            ->add('approverUsers', ChoiceType::class, [
                'label' => 'admin.workflows.form.approver_users',
                'required' => false,
                'multiple' => true,
                'choices' => $options['user_choices'],
                'choice_translation_domain' => false,
                'attr' => ['data-controller' => 'tom-select'],
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
            // Same keys the YAML `auto_progress_conditions` and
            // WorkflowAutoProgressionService / ProcessTimedWorkflowsCommand read.
            ->add('autoProgressConditions', JsonBuilderType::class, [
                'label' => 'admin.workflows.form.auto_progress_conditions',
                'required' => false,
                'help' => 'admin.workflows.form.auto_progress_conditions_help',
                'shape' => 'object',
                'fields' => [
                    ['key' => 'type', 'type' => 'select', 'label' => 'admin.workflows.conditions.type', 'required' => true, 'choices' => [
                        'admin.workflows.conditions.type_field_completion' => 'field_completion',
                        'admin.workflows.conditions.type_auto' => 'auto',
                        'admin.workflows.conditions.type_risk_appetite' => 'risk_appetite',
                        'admin.workflows.conditions.type_time_based' => 'time_based',
                    ]],
                    ['key' => 'entity', 'type' => 'text', 'label' => 'admin.workflows.conditions.entity',
                        'help' => 'admin.workflows.conditions.entity_help'],
                    ['key' => 'fields', 'type' => 'tags', 'label' => 'admin.workflows.conditions.fields',
                        'help' => 'admin.workflows.conditions.fields_help'],
                    ['key' => 'condition', 'type' => 'text', 'label' => 'admin.workflows.conditions.condition', 'wide' => true,
                        'help' => 'admin.workflows.conditions.condition_help'],
                    ['key' => 'riskScoreField', 'type' => 'text', 'label' => 'admin.workflows.conditions.risk_score_field'],
                    ['key' => 'delay', 'type' => 'text', 'label' => 'admin.workflows.conditions.delay',
                        'help' => 'admin.workflows.conditions.delay_help'],
                ],
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
            // label => user id; the controller passes the tenant's active users.
            'user_choices' => [],
        ]);
        $resolver->setAllowedTypes('user_choices', 'array');
    }

    /**
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'responsibility' => ['approverRole', 'approverUsers'],
            'schedule'       => ['daysToComplete'],
            'approval'       => ['reasonRequired', 'reasonRequiredOverride', 'fourEyes', 'fourEyesOverride'],
            'advanced'       => ['autoProgressConditions', 'module'],
        ];
    }
}
