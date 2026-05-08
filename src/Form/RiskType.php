<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\User;
use App\Entity\Vulnerability;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class RiskType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentTenant = $this->tenantContext->getCurrentTenant();

        $builder
            ->add('title', TextType::class, [
                'label' => 'risk.field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'risk.placeholder.title',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'risk.field.category',
                'choices' => [
                    'risk.category.financial' => 'financial',
                    'risk.category.operational' => 'operational',
                    'risk.category.compliance' => 'compliance',
                    'risk.category.strategic' => 'strategic',
                    'risk.category.reputational' => 'reputational',
                    'risk.category.security' => 'security',
                ],
                'placeholder' => 'risk.placeholder.category',
                'required' => true,
                'help' => 'risk.help.category',
                'attr' => [
                    'class' => 'form-select',
                ],
                'choice_translation_domain' => 'risk',
            ])
        ;

        // ── GDPR subset — only shown when 'privacy' module is active ──────────
        if ($this->isModuleActive('privacy')) {
            $builder
                ->add('involvesPersonalData', CheckboxType::class, [
                    'label' => 'risk.field.involves_personal_data',
                    'required' => false,
                    'help' => 'risk.help.involves_personal_data',
                ])
                ->add('involvesSpecialCategoryData', CheckboxType::class, [
                    'label' => 'risk.field.involves_special_category_data',
                    'required' => false,
                    'help' => 'risk.help.involves_special_category_data',
                    'attr' => [
                        'data-depends-on' => 'risk_form_involvesPersonalData',
                        'data-depends-on-value' => '1',
                    ],
                ])
                ->add('legalBasis', ChoiceType::class, [
                    'label' => 'risk.field.legal_basis',
                    'choices' => [
                        'risk.legal_basis.consent' => 'consent',
                        'risk.legal_basis.contract' => 'contract',
                        'risk.legal_basis.legal_obligation' => 'legal_obligation',
                        'risk.legal_basis.vital_interests' => 'vital_interests',
                        'risk.legal_basis.public_task' => 'public_task',
                        'risk.legal_basis.legitimate_interests' => 'legitimate_interests',
                    ],
                    'placeholder' => 'risk.placeholder.legal_basis',
                    'required' => false,
                    'help' => 'risk.help.legal_basis',
                    'attr' => [
                        'class' => 'form-select',
                        'data-depends-on' => 'risk_form_involvesPersonalData',
                        'data-depends-on-value' => '1',
                    ],
                    'choice_translation_domain' => 'risk',
                ])
                ->add('processingScale', ChoiceType::class, [
                    'label' => 'risk.field.processing_scale',
                    'choices' => [
                        'risk.processing_scale.small' => 'small',
                        'risk.processing_scale.medium' => 'medium',
                        'risk.processing_scale.large_scale' => 'large_scale',
                    ],
                    'placeholder' => 'risk.placeholder.processing_scale',
                    'required' => false,
                    'help' => 'risk.help.processing_scale',
                    'attr' => [
                        'class' => 'form-select',
                        'data-depends-on' => 'risk_form_involvesPersonalData',
                        'data-depends-on-value' => '1',
                    ],
                    'choice_translation_domain' => 'risk',
                ])
                ->add('requiresDPIA', CheckboxType::class, [
                    'label' => 'risk.field.requires_dpia',
                    'required' => false,
                    'help' => 'risk.help.requires_dpia',
                    'attr' => [
                        'data-depends-on' => 'risk_form_involvesPersonalData',
                        'data-depends-on-value' => '1',
                    ],
                ])
                ->add('dataSubjectImpact', TextareaType::class, [
                    'label' => 'risk.field.data_subject_impact',
                    'required' => false,
                    'attr' => [
                        'rows' => 3,
                        'placeholder' => 'risk.placeholder.data_subject_impact',
                        'data-depends-on' => 'risk_form_involvesPersonalData',
                        'data-depends-on-value' => '1',
                    ],
                    'help' => 'risk.help.data_subject_impact',
                ])
            ;
        }

        $builder
            ->add('description', TextareaType::class, [
                'label' => 'risk.field.description',
                'required' => true,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.placeholder.description',
                ],
                'help' => 'risk.help.description',
            ])
            ->add('threat', TextareaType::class, [
                'label' => 'risk.field.threat',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.threat',
                ],
                'help' => 'risk.help.threat',
            ])
            ->add('threatIntelligence', EntityType::class, [
                'label' => 'risk.field.threat_intelligence',
                'class' => ThreatIntelligence::class,
                'choice_label' => fn(ThreatIntelligence $t): string => (string) ($t->getTitle() ?? ''),
                'required' => false,
                'placeholder' => 'risk.placeholder.threat_intelligence',
                'attr' => ['class' => 'form-select'],
                'help' => 'risk.help.threat_intelligence',
            ])
            ->add('vulnerability', TextareaType::class, [
                'label' => 'risk.field.vulnerability',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.vulnerability',
                ],
                'help' => 'risk.help.vulnerability',
            ])
            ->add('linkedVulnerability', EntityType::class, [
                'label' => 'risk.field.linked_vulnerability',
                'class' => Vulnerability::class,
                'choice_label' => fn(Vulnerability $v): string => ($v->getCveId() ?? '') . ' — ' . ($v->getTitle() ?? ''),
                'required' => false,
                'placeholder' => 'risk.placeholder.linked_vulnerability',
                'attr' => ['class' => 'form-select'],
                'help' => 'risk.help.linked_vulnerability',
            ])
            // Risk Subject - At least one must be selected (Asset, Person, Location, or Supplier)
            ->add('asset', EntityType::class, [
                'label' => 'risk.field.asset',
                'class' => Asset::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.asset',
                'required' => false,
                'help' => 'risk.help.asset',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('person', EntityType::class, [
                'label' => 'risk.field.person',
                'class' => Person::class,
                'choice_label' => fn(Person $person): ?string => $person->getFullName(),
                'placeholder' => 'risk.placeholder.person',
                'required' => false,
                'help' => 'risk.help.person',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('location', EntityType::class, [
                'label' => 'risk.field.location',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.location',
                'required' => false,
                'help' => 'risk.help.location',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('supplier', EntityType::class, [
                'label' => 'risk.field.supplier',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.supplier',
                'required' => false,
                'help' => 'risk.help.supplier',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('probability', IntegerType::class, [
                'label' => 'risk.field.probability',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.probability',
            ])
            ->add('likelihoodJustification', TextareaType::class, [
                'label' => 'risk.field.likelihood_justification',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'risk.validation.likelihood_justification_required'),
                ],
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.likelihood_justification',
                ],
                'help' => 'risk.help.likelihood_justification',
            ])
            ->add('impact', IntegerType::class, [
                'label' => 'risk.field.impact',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.impact',
            ])
            ->add('impactJustification', TextareaType::class, [
                'label' => 'risk.field.impact_justification',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'risk.validation.impact_justification_required'),
                ],
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.impact_justification',
                ],
                'help' => 'risk.help.impact_justification',
            ])
            ->add('residualProbability', IntegerType::class, [
                'label' => 'risk.field.residual_probability',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.residual_probability',
            ])
            ->add('residualImpact', IntegerType::class, [
                'label' => 'risk.field.residual_impact',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.residual_impact',
            ])
            ->add('riskOwner', EntityType::class, [
                'label' => 'risk.field.risk_owner',
                'class' => User::class,
                'choice_label' => fn(User $user): string => $user->getFullName() . ' (' . $user->getEmail() . ')',
                'placeholder' => 'risk.placeholder.risk_owner',
                'required' => false,
                'help' => 'risk.help.risk_owner',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('riskOwnerPerson', EntityType::class, [
                'label' => 'risk.field.risk_owner_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'risk.placeholder.risk_owner_person',
                'attr' => ['class' => 'form-select'],
                'help' => 'risk.help.risk_owner_person',
            ])
            ->add('riskOwnerDeputyPersons', EntityType::class, [
                'label' => 'risk.field.risk_owner_deputies',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'risk.help.risk_owner_deputies',
            ])
            ->add('treatmentStrategy', EnumType::class, [
                'label' => 'risk.field.treatment_strategy',
                'class' => TreatmentStrategy::class,
                'choice_label' => fn(TreatmentStrategy $t): string => 'risk.treatment.' . $t->value,
                'placeholder' => 'risk.placeholder.treatment_strategy',
                'required' => false,
                'help' => 'risk.help.treatment',
                'choice_translation_domain' => 'risk',
            ])
            ->add('treatmentDescription', TextareaType::class, [
                'label' => 'risk.field.treatment_description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.placeholder.treatment_description',
                ],
                'help' => 'risk.help.treatment_description',
            ])
            // Decision / Acceptance fields (replaces plain-text acceptanceApprovedBy — PT-F01 CVSS 9.1 fix)
            ->add('decisionApprovedByUser', EntityType::class, [
                'label' => 'risk.field.decision_approved_by_user',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'risk.placeholder.decision_approved_by_user',
                'attr' => ['class' => 'form-select'],
                'help' => 'risk.help.decision_approved_by_user',
                'query_builder' => function (EntityRepository $er) use ($currentTenant) {
                    $qb = $er->createQueryBuilder('u')
                        ->where('u.roles LIKE :mgr OR u.roles LIKE :admin OR u.roles LIKE :super')
                        ->setParameter('mgr', '%ROLE_MANAGER%')
                        ->setParameter('admin', '%ROLE_ADMIN%')
                        ->setParameter('super', '%ROLE_SUPER_ADMIN%')
                        ->orderBy('u.lastName', 'ASC');
                    if ($currentTenant !== null) {
                        $qb->andWhere('u.tenant = :tenant')
                           ->setParameter('tenant', $currentTenant);
                    }
                    return $qb;
                },
            ])
            ->add('decisionApprovalDate', DateTimeType::class, [
                'label' => 'risk.field.decision_approval_date',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('decisionRationale', TextareaType::class, [
                'label' => 'risk.field.decision_rationale',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.decision_rationale',
                ],
                'help' => 'risk.help.decision_rationale',
            ])
            ->add('acceptanceApprovedByUser', EntityType::class, [
                'label' => 'risk.field.acceptance_approved_by',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'risk.placeholder.acceptance_approved_by_user',
                'attr' => ['class' => 'form-select'],
                'help' => 'risk.help.acceptance_approved_by_user',
            ])
            ->add('acceptanceApprovedAt', DateType::class, [
                'label' => 'risk.field.acceptance_approved_at',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'risk.help.acceptance_approved_at',
            ])
            ->add('acceptanceJustification', TextareaType::class, [
                'label' => 'risk.field.acceptance_justification',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.acceptance_justification',
                ],
                'help' => 'risk.help.acceptance_justification',
            ])
            ->add('status', EnumType::class, [
                'label' => 'risk.field.status',
                'class' => RiskStatus::class,
                'choice_label' => fn(RiskStatus $s): string => 'risk.status.' . $s->value,
                'required' => true,
                'choice_translation_domain' => 'risk',
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'risk.field.review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'risk.help.review_date',
            ])
        ;

        // ── DORA ICT-Risk subset — only shown when 'nis2_dora' module is active ─
        if ($this->isModuleActive('nis2_dora')) {
            $this->addDoraIctRiskFields($builder, $currentTenant);
        }
    }

    private function addDoraIctRiskFields(FormBuilderInterface $builder, mixed $currentTenant): void
    {
        $builder
            ->add('ictRiskCategory', ChoiceType::class, [
                'choices' => [
                    'risk.dora.ict_category.cyber' => 'cyber',
                    'risk.dora.ict_category.operations' => 'operations',
                    'risk.dora.ict_category.third_party_ict' => 'third_party_ict',
                    'risk.dora.ict_category.concentration' => 'concentration',
                    'risk.dora.ict_category.data_integrity' => 'data_integrity',
                ],
                'choice_translation_domain' => 'risk',
                'label' => 'risk.dora.field.ict_risk_category',
                'required' => false,
                'placeholder' => 'risk.dora.placeholder.ict_risk_category',
                'attr' => ['class' => 'form-select'],
                'help' => 'risk.dora.help.ict_risk_category',
            ])
            ->add('criticalOrImportantFunction', CheckboxType::class, [
                'label' => 'risk.dora.field.critical_or_important_function',
                'required' => false,
                'help' => 'risk.dora.help.critical_or_important_function',
            ])
            ->add('ictThirdPartyConcentration', CheckboxType::class, [
                'label' => 'risk.dora.field.ict_third_party_concentration',
                'required' => false,
                'help' => 'risk.dora.help.ict_third_party_concentration',
            ])
            ->add('ictAssetDependency', EntityType::class, [
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'risk.dora.field.ict_asset_dependency',
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'risk.dora.help.ict_asset_dependency',
            ])
            ->add('ictIncidentHistory', EntityType::class, [
                'class' => Incident::class,
                'choice_label' => 'title',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'risk.dora.field.ict_incident_history',
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'risk.dora.help.ict_incident_history',
            ])
            ->add('dataResilienceRequirement', TextareaType::class, [
                'label' => 'risk.dora.field.data_resilience_requirement',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.dora.placeholder.data_resilience_requirement',
                ],
                'help' => 'risk.dora.help.data_resilience_requirement',
            ])
            ->add('tlptScope', CheckboxType::class, [
                'label' => 'risk.dora.field.tlpt_scope',
                'required' => false,
                'help' => 'risk.dora.help.tlpt_scope',
            ])
            ->add('regulatoryReportingRequired', CheckboxType::class, [
                'label' => 'risk.dora.field.regulatory_reporting_required',
                'required' => false,
                'help' => 'risk.dora.help.regulatory_reporting_required',
            ])
            ->add('boardEscalationRequired', CheckboxType::class, [
                'label' => 'risk.dora.field.board_escalation_required',
                'required' => false,
                'help' => 'risk.dora.help.board_escalation_required',
            ])
            ->add('lessonsLearnedDocumented', CheckboxType::class, [
                'label' => 'risk.dora.field.lessons_learned_documented',
                'required' => false,
                'help' => 'risk.dora.help.lessons_learned_documented',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Risk::class,
            'translation_domain' => 'risk',
            'constraints' => [
                new Callback([$this, 'validateRiskOwnerSlot']),
            ],
        ]);
    }

    public function validateRiskOwnerSlot(?Risk $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getRiskOwner() === null && $entity->getRiskOwnerPerson() === null) {
            $context->buildViolation('risk.error.owner_required_user_or_person')
                ->atPath('riskOwner')
                ->addViolation();
        }
    }
}
