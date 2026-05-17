<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\CrisisTeam;
use App\Form\DataTransformer\JsonArrayTransformer;
use App\Form\Trait\OwnerPickerFormTrait;
use App\Repository\CrisisTeamRepository;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class BusinessContinuityPlanType extends AbstractType implements SectionMapInterface
{
    use OwnerPickerFormTrait;

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'bc_plans.field.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
                'help' => 'bc_plans.help.name',
            ])
            ->add('businessProcess', EntityType::class, [
                'label' => 'bc_plans.field.business_process',
                'class' => BusinessProcess::class,
                'choice_label' => 'name',
                'required' => true,
                'help' => 'bc_plans.help.business_process',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'bc_plans.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.description',
            ])
            ->add('bcTeam', TextareaType::class, [
                'label' => 'bc_plans.field.bc_team',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.bc_team',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'bc_plans.field.status',
                'choices' => [
                    'bc_plans.status.draft' => 'draft',
                    'bc_plans.status.under_review' => 'under_review',
                    'bc_plans.status.active' => 'active',
                    'bc_plans.status.archived' => 'archived',
                ],
                'choice_translation_domain' => 'bc_plans',
                'required' => true,
                'help' => 'bc_plans.help.status',
            ])
            ->add('activationCriteria', TextareaType::class, [
                'label' => 'bc_plans.field.activation_criteria',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.activation_criteria',
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'bc_plans.field.roles_and_responsibilities',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.roles_responsibilities',
            ])
            ->add('recoveryProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.recovery_procedures',
                'required' => false,
                'attr' => ['rows' => 6],
                'help' => 'bc_plans.help.recovery_procedures',
            ])
            // ISO 22301 Cl. 8.2.2 — Recovery targets. P0: previously missing from form.
            ->add('rto', IntegerType::class, [
                'label' => 'bc_plans.field.rto',
                'required' => true,
                'attr' => ['min' => 0, 'max' => 8760],
                'help' => 'bc_plans.help.rto',
            ])
            ->add('rpo', IntegerType::class, [
                'label' => 'bc_plans.field.rpo',
                'required' => true,
                'attr' => ['min' => 0, 'max' => 8760],
                'help' => 'bc_plans.help.rpo',
            ])
            ->add('criticalAssets', EntityType::class, [
                'label' => 'bc_plans.field.critical_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'bc_plans.help.critical_assets',
            ])
            ->add('communicationPlan', TextareaType::class, [
                'label' => 'bc_plans.field.communication_plan',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.communication_plan',
            ])
            ->add('internalCommunication', TextareaType::class, [
                'label' => 'bc_plans.field.internal_communication',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.internal_communication',
            ])
            ->add('externalCommunication', TextareaType::class, [
                'label' => 'bc_plans.field.external_communication',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.external_communication',
            ])
            ->add('alternativeSite', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'bc_plans.help.alternative_site',
            ])
            ->add('alternativeSiteAddress', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site_address',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'bc_plans.help.alternative_site_address',
            ])
            ->add('alternativeSiteCapacity', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site_capacity',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'bc_plans.help.alternative_site_capacity',
            ])
            ->add('backupProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.backup_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.backup_procedures',
            ])
            ->add('restoreProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.restore_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.restore_procedures',
            ])
            ->add('version', TextType::class, [
                'label' => 'bc_plans.field.version',
                'required' => true,
                'attr' => ['maxlength' => 20],
                'help' => 'bc_plans.help.version',
            ])
            ->add('lastTested', DateType::class, [
                'label' => 'bc_plans.field.last_tested',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.last_tested',
            ])
            ->add('nextTestDate', DateType::class, [
                'label' => 'bc_plans.field.next_test_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.next_test_date',
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'bc_plans.field.last_review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.last_review_date',
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'bc_plans.field.next_review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.next_review_date',
            ])
            ->add('reviewNotes', TextareaType::class, [
                'label' => 'bc_plans.field.review_notes',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.review_notes',
            ])
            ->add('responseTeamMembers', TextareaType::class, [
                'label' => 'bc_plans.field.response_team_members',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'placeholder' => 'bc_plans.placeholder.response_team_members',
                ],
                'help' => 'bc_plans.help.response_team_members_json',
            ])
            ->add('requiredResources', TextareaType::class, [
                'label' => 'bc_plans.field.required_resources',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.required_resources_json',
            ])
            ->add('escalationLevels', TextareaType::class, [
                'label' => 'bc_plans.field.escalation_levels',
                'required' => false,
                'attr' => ['rows' => 5],
                'help' => 'bc_plans.help.escalation_levels_json',
            ])
            ->add('crisisTeams', EntityType::class, [
                'class' => CrisisTeam::class,
                'choice_label' => 'teamName',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'bc_plans.field.crisis_teams',
                'help' => 'bc_plans.help.crisis_teams',
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'query_builder' => function (CrisisTeamRepository $r) {
                    return $r->createQueryBuilder('ct')
                        ->where('ct.tenant = :tenant')
                        ->setParameter('tenant', $this->tenantContext->getCurrentTenant())
                        ->orderBy('ct.teamName', 'ASC');
                },
            ])
        ;

        // S4 P-1 Wave-2 — Plan-Owner compound slot. Replaces the inline
        // 4-field block (planOwnerUser + planOwnerPerson +
        // planOwnerDeputyPersons + planOwner legacy text).
        // Legacy free-text `planOwner` is preserved as read-only Migration-Hint
        // (rendered only when populated — see _fa_owner_picker macro).
        $this->addOwnerPicker($builder, [
            'field_prefix'       => 'planOwner',
            'user_field'         => 'planOwnerUser',
            'person_field'       => 'planOwnerPerson',
            'deputies_field'     => 'planOwnerDeputyPersons',
            'legacy_field'       => 'planOwner',
            'label_user'         => 'bc_plans.field.plan_owner',
            'label_person'       => 'bc_plans.field.plan_owner_person',
            'label_deputies'     => 'bc_plans.field.plan_owner_deputies',
            'label_legacy'       => 'bc_plans.field.plan_owner_legacy',
            'placeholder_user'   => 'bc_plans.placeholder.plan_owner_user',
            'placeholder_person' => 'bc_plans.placeholder.plan_owner_person',
            'help_user'          => 'bc_plans.help.plan_owner_user',
            'help_person'        => 'bc_plans.help.plan_owner_person',
            'help_deputies'      => 'bc_plans.help.plan_owner_deputies',
            'with_deputies'      => true,
            'with_legacy'        => true,
        ]);

        $builder->get('responseTeamMembers')->addModelTransformer(new JsonArrayTransformer());
        $builder->get('requiredResources')->addModelTransformer(new JsonArrayTransformer());
        $builder->get('escalationLevels')->addModelTransformer(new JsonArrayTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessContinuityPlan::class,
            'translation_domain' => 'bc_plans',
            'label_translation_parameters' => [
                '%business_process%' => '{{ businessProcess.name }}',
            ],
            'attr' => [
                'novalidate' => 'novalidate',
            ],
            'empty_data' => 'new',
            'constraints' => [
                new Callback([$this, 'validatePlanOwnerSlot']),
            ],
        ]);
    }

    public function validatePlanOwnerSlot(?BusinessContinuityPlan $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getPlanOwnerUser() === null && $entity->getPlanOwnerPerson() === null) {
            $context->buildViolation('bc_plans.error.owner_required_user_or_person')
                ->atPath('planOwnerUser')
                ->addViolation();
        }
    }

    /**
     * S4 Foundation P-2 SectionPolicy — explicit field-to-section map for the
     * `_auto_form.html.twig` renderer. Each field added via the form builder
     * MUST appear in exactly one section (CI-gated by
     * `scripts/quality/check_form_sections.py`).
     *
     * Sections follow ISO 22301 / BSI 200-4 BCM-Plan structure:
     * - overview:   identity, scope, ownership
     * - recovery:   RTO/RPO targets, recovery procedures, critical assets
     * - team:       response team, crisis teams, escalation paths
     * - communication: internal/external comms plans + stakeholder lists
     * - resources:  alternative sites, required resources, backup/restore
     * - activation: trigger criteria + roles
     * - testing:    test schedule + review cadence
     * - audit_metadata: version, status, review notes
     */
    public static function getSectionMap(): array
    {
        return [
            'overview' => [
                'name',
                'businessProcess',
                'description',
                'planOwnerUser',
                'planOwnerPerson',
                'planOwnerDeputyPersons',
                'planOwner',
            ],
            'recovery' => [
                'rto',
                'rpo',
                'criticalAssets',
                'recoveryProcedures',
            ],
            'team' => [
                'bcTeam',
                'responseTeamMembers',
                'crisisTeams',
                'escalationLevels',
            ],
            'communication' => [
                'communicationPlan',
                'internalCommunication',
                'externalCommunication',
            ],
            'resources' => [
                'alternativeSite',
                'alternativeSiteAddress',
                'alternativeSiteCapacity',
                'requiredResources',
                'backupProcedures',
                'restoreProcedures',
            ],
            'activation' => [
                'activationCriteria',
                'rolesAndResponsibilities',
            ],
            'testing' => [
                'lastTested',
                'nextTestDate',
                'lastReviewDate',
                'nextReviewDate',
                'reviewNotes',
            ],
            'audit_metadata' => [
                'version',
                'status',
            ],
        ];
    }
}
