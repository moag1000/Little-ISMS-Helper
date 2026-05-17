<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
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
use App\Form\Trait\OwnerPickerFormTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class RiskType extends AbstractType
{
    use ModuleAwareFormTrait;
    use OwnerPickerFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
                                    'choice_translation_domain' => 'risk',
            ])
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
            ->add('vulnerability', TextareaType::class, [
                'label' => 'risk.field.vulnerability',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.vulnerability',
                ],
                'help' => 'risk.help.vulnerability',
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
            ->add('impact', IntegerType::class, [
                'label' => 'risk.field.impact',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.impact',
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
        ;

        // ── Risk Owner cluster (audit-s4 P-1) ───────────────────────────────
        // Replaces 3 hand-rolled add() calls (riskOwner / riskOwnerPerson /
        // riskOwnerDeputyPersons). Risk has no legacy free-text field.
        $this->addOwnerPicker($builder, [
            'user_field'         => 'riskOwner',
            'person_field'       => 'riskOwnerPerson',
            'deputies_field'     => 'riskOwnerDeputyPersons',
            'legacy_field'       => null,
            'translation_prefix' => 'risk',
            'user_label'         => 'risk.field.risk_owner',
            'user_placeholder'   => 'risk.placeholder.risk_owner',
            'user_help'          => 'risk.help.risk_owner',
            'person_label'       => 'risk.field.risk_owner_person',
            'person_placeholder' => 'risk.placeholder.risk_owner_person',
            'person_help'        => 'risk.help.risk_owner_person',
            'deputies_label'     => 'risk.field.risk_owner_deputies',
            'deputies_help'      => 'risk.help.risk_owner_deputies',
        ]);

        $builder
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
            ->add('acceptanceApprovedByUser', EntityType::class, [
                'label' => 'risk.field.acceptance_approved_by',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'risk.placeholder.acceptance_approved_by_user',
                'help' => 'risk.help.acceptance_approved_by_user',
            ])
            ->add('acceptanceApprovedBy', TextType::class, [
                'label' => 'risk.field.acceptance_approved_by_legacy',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'risk.placeholder.acceptance_approved_by',
                ],
                'help' => 'risk.help.acceptance_approved_by',
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
            // ISO 27001 Cl. 8.3 — Audit-V3 LB-7: acceptance must carry an
            // expiry / re-evaluation date so accepted risks do not "expire
            // silent" after the approver leaves the org or threat changes.
            ->add('acceptanceExpiryDate', DateType::class, [
                'label' => 'risk.field.acceptance_expiry_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'risk.help.acceptance_expiry_date',
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

        // ── DSGVO/GDPR Risk Assessment Extension — only when 'privacy' module active.
        // DSGVO Art. 24 + Art. 32 + Art. 35 — risk-of-rights-and-freedoms-of-natural-persons
        // assessment. Without privacy-module these fields would be noise.
        if ($this->isModuleActive('privacy')) {
            $this->addGdprFields($builder);
        }

        // ── Vulnerability & Threat-Intel cross-link — only when 'vulnerability_intel'
        // module is active. CVE/CVSS pivoting + threat-intel correlation is a
        // niche capability (NIS2 Art. 21.2(e) + DORA Art. 22 use-case).
        if ($this->isModuleActive('vulnerability_intel')) {
            $this->addVulnerabilityIntelFields($builder);
        }
    }

    /**
     * DSGVO Art. 35 (DPIA) / Art. 32 (Risk-of-Processing) fields.
     * Only added when 'privacy' module is active.
     */
    private function addGdprFields(FormBuilderInterface $builder): void
    {
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
                'choice_translation_domain' => 'risk',
            ])
            ->add('requiresDPIA', CheckboxType::class, [
                'label' => 'risk.field.requires_dpia',
                'required' => false,
                'help' => 'risk.help.requires_dpia',
            ])
            ->add('dataSubjectImpact', TextareaType::class, [
                'label' => 'risk.field.data_subject_impact',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.data_subject_impact',
                ],
                'help' => 'risk.help.data_subject_impact',
            ])
        ;
    }

    /**
     * CVE/CVSS Vulnerability + MITRE/STIX Threat-Intelligence cross-links.
     * Only added when 'vulnerability_intel' module is active.
     */
    private function addVulnerabilityIntelFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('threatIntelligence', EntityType::class, [
                'label' => 'risk.field.threat_intelligence',
                'class' => ThreatIntelligence::class,
                'choice_label' => fn(ThreatIntelligence $t): string => (string) ($t->getTitle() ?? ''),
                'required' => false,
                'placeholder' => 'risk.placeholder.threat_intelligence',
                'help' => 'risk.help.threat_intelligence',
            ])
            ->add('linkedVulnerability', EntityType::class, [
                'label' => 'risk.field.linked_vulnerability',
                'class' => Vulnerability::class,
                'choice_label' => fn(Vulnerability $v): string => ($v->getCveId() ?? '') . ' — ' . ($v->getTitle() ?? ''),
                'required' => false,
                'placeholder' => 'risk.placeholder.linked_vulnerability',
                'help' => 'risk.help.linked_vulnerability',
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
                new Callback([$this, 'validateRiskSubjectSlot']),
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

    /**
     * ISO 27001 Cl. 6.1.2 c — risks MUST be tied to identifiable assets,
     * persons, locations, suppliers, or business processes. A risk
     * "in general" without subject is a textbook Major-NC at external
     * audit: the auditor's first question is "which asset/process?" and
     * "weiss nicht" wins them their finding.
     *
     * Junior-ISB-audit P0-02: the form previously let users save risks
     * with no subject reference at all. This callback closes that hole.
     */
    public function validateRiskSubjectSlot(?Risk $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getAsset() === null
            && $entity->getPerson() === null
            && $entity->getLocation() === null
            && $entity->getSupplier() === null
        ) {
            $context->buildViolation('risk.error.subject_required')
                ->atPath('asset')
                ->addViolation();
        }
    }
}
