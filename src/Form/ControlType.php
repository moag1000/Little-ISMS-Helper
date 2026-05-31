<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\User;
use App\Entity\Asset;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Repository\RiskRepository;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ControlType extends AbstractType implements SectionMapInterface
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('controlId', TextType::class, [
                'label' => 'control.field.control_id',
                'attr' => [
                    'placeholder' => 'control.placeholder.control_id',
                    'readonly' => !$options['allow_control_id_edit'],
                ],
                'help' => 'control.help.control_id',
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'control.field.name',
                'attr' => [
                    'placeholder' => 'control.placeholder.name',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'control.field.description',
                'required' => true,
                'attr' => [
                    'rows' => 4,
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'control.field.category',
                'choices' => [
                    'control.category.organizational' => 'organizational',
                    'control.category.people' => 'people',
                    'control.category.physical' => 'physical',
                    'control.category.technological' => 'technological',
                ],
                'choice_translation_domain' => 'control',
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            // Junior-ISB-Audit-2026-05-22 S-07: the radios are discovered by
            // name-pattern (`[applicable]`) inside the `applicability-toggle`
            // Stimulus controller (see `configureOptions` form-root
            // data-controller below); no per-radio attrs needed.
            ->add('applicable', ChoiceType::class, [
                'label' => 'control.field.applicable',
                'choices' => [
                    'control.applicable.yes' => true,
                    'control.applicable.no' => false,
                ],
                'choice_translation_domain' => 'control',
                'expanded' => true,
                'attr' => [
                    'class' => 'form-check',
                    'data-applicability-toggle-target' => 'trigger',
                ],
                'help' => 'control.help.applicable_explained',
            ])
            // Junior-ISB-Audit-2026-05-22 S-07: declared as Stimulus target so
            // the controller can toggle aria-required + visible `*` marker
            // when `applicable=false` (ISO 27001 6.1.3 d / 8.3 b).
            ->add('justification', TextareaType::class, [
                'label' => 'control.field.justification',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'control.placeholder.justification',
                    'data-applicability-toggle-target' => 'field',
                ],
                'help' => 'control.help.justification',
            ])
            // F12 — per-option descriptions live on each choice via
            // choice_attr (title + data-description), so the wall-of-text help
            // is broken up; only the "only applicable controls count" caveat
            // remains as field-level help.
            ->add('implementationStatus', ChoiceType::class, [
                'label' => 'control.field.implementation_status',
                'choices' => [
                    'control.implementation_status.not_started' => 'not_started',
                    'control.implementation_status.planned' => 'planned',
                    'control.implementation_status.in_progress' => 'in_progress',
                    'control.implementation_status.implemented' => 'implemented',
                    'control.implementation_status.verified' => 'verified',
                ],
                'choice_attr' => fn(string $choice): array => [
                    'title' => 'control.implementation_status_desc.' . $choice,
                    'data-description' => 'control.implementation_status_desc.' . $choice,
                ],
                'choice_translation_domain' => 'control',
                'attr' => [
                    'data-control-status-target' => 'status',
                    'data-action' => 'change->control-status#onChange',
                ],
                'help' => 'control.help.implementation_status_caveat',
            ])
            ->add('implementationPercentage', IntegerType::class, [
                'label' => 'control.field.implementation_percentage',
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'data-control-status-target' => 'percentage',
                ],
                'constraints' => [
                    new Range(min: 0, max: 100),
                ],
                'help' => 'control.help.implementation_percentage',
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'control.field.implementation_notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'control.help.implementation_notes',
            ])
            ->add('responsiblePersonUser', EntityType::class, [
                'label' => 'control.field.responsible_person',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'control.placeholder.responsible_person_user',
                'help' => 'control.help.responsible_person_user',
            ])
            ->add('responsiblePersonRef', EntityType::class, [
                'label' => 'control.field.responsible_person_contact',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'control.placeholder.responsible_person_contact',
                'help' => 'control.help.responsible_person_contact',
            ])
            ->add('responsibleDeputyPersons', EntityType::class, [
                'label' => 'control.field.responsible_deputies',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'control.help.responsible_deputies',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'control.field.responsible_person_legacy',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'control.placeholder.responsible_person',
                ],
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'control.field.target_date',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'control.help.target_date',
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'control.field.last_review_date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'control.field.next_review_date',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'control.help.next_review_date',
            ])
            ->add('protectedAssets', EntityType::class, [
                'label' => 'control.field.protected_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'size' => 5,
                    'data-controller' => 'tom-select',
                ],
                'help' => 'control.help.protected_assets',
            ])
            // ── Sprint 6: Always-visible effectiveness + classification fields ──
            ->add('effectiveness', ChoiceType::class, [
                'choices' => [
                    'control.effectiveness.not_assessed' => 'not_assessed',
                    'control.effectiveness.ineffective' => 'ineffective',
                    'control.effectiveness.partially_effective' => 'partially_effective',
                    'control.effectiveness.effective' => 'effective',
                    'control.effectiveness.highly_effective' => 'highly_effective',
                ],
                'choice_translation_domain' => 'control',
                'label' => 'control.field.effectiveness',
                'required' => false,
                'placeholder' => 'control.placeholder.effectiveness',
            ])
            ->add('controlType', ChoiceType::class, [
                'choices' => [
                    'control.type.preventive' => 'preventive',
                    'control.type.detective' => 'detective',
                    'control.type.corrective' => 'corrective',
                    'control.type.deterrent' => 'deterrent',
                    'control.type.recovery' => 'recovery',
                ],
                'choice_translation_domain' => 'control',
                'label' => 'control.field.control_type',
                'required' => false,
                'placeholder' => 'control.placeholder.control_type',
            ])
            ->add('automationLevel', ChoiceType::class, [
                'choices' => [
                    'control.automation.manual' => 'manual',
                    'control.automation.semi_automated' => 'semi_automated',
                    'control.automation.fully_automated' => 'fully_automated',
                ],
                'choice_translation_domain' => 'control',
                'label' => 'control.field.automation_level',
                'required' => false,
                'placeholder' => 'control.placeholder.automation_level',
            ])
            ->add('controlMaturity', ChoiceType::class, [
                'choices' => [
                    'control.maturity.1_initial' => 1,
                    'control.maturity.2_managed' => 2,
                    'control.maturity.3_defined' => 3,
                    'control.maturity.4_quantitatively_managed' => 4,
                    'control.maturity.5_optimizing' => 5,
                ],
                'choice_translation_domain' => 'control',
                'label' => 'control.field.control_maturity',
                'required' => false,
                'placeholder' => 'control.placeholder.control_maturity',
            ])
            ->add('lastEffectivenessTest', DateType::class, [
                'widget' => 'single_text',
                'label' => 'control.field.last_effectiveness_test',
                'required' => false,
            ])
            ->add('nextEffectivenessTest', DateType::class, [
                'widget' => 'single_text',
                'label' => 'control.field.next_effectiveness_test',
                'required' => false,
            ])
            // F8 — evidence documents (ISO 27001 Cl. 7.5). Multiple-select
            // backed by the control_evidence join table; TomSelect autocomplete.
            ->add('evidenceDocuments', EntityType::class, [
                'class' => Document::class,
                'choice_label' => 'originalFilename',
                'label' => 'control.field.evidence_documents',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'control.help.evidence_documents',
            ])
            // S5 Bucket 5 (item 5.5) — proper FormType for the variable-key
            // associative map `array<framework_slug, list<reference_id>>`.
            // One labelled chip-row per known framework slug; legacy custom
            // slugs are surfaced dynamically via PRE_SET_DATA so they survive
            // round-trips. Backed by FrameworkReferencesTransformer for the
            // CSV ↔ list-per-slug shape conversion.
            ->add('frameworkReferences', ControlFrameworkReferencesType::class, [
                'label' => 'control.field.framework_references',
                'required' => false,
                'help' => 'control.help.framework_references_chip',
            ])
            ->add('risks', EntityType::class, [
                'class' => Risk::class,
                'choice_label' => 'title',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'control.field.related_risks',
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'query_builder' => function (RiskRepository $r) {
                    $qb = $r->createQueryBuilder('r');
                    $tenant = $this->tenantContext->getCurrentTenant();
                    if ($tenant !== null) {
                        $qb->where('r.tenant = :tenant')->setParameter('tenant', $tenant);
                    }
                    return $qb;
                },
            ]);

        // frameworkReferences shape conversion now lives inside
        // ControlFrameworkReferencesType via FrameworkReferencesTransformer.

        // ── Cloud-fields gated by 'cloud_security' module ─────────────────────
        if ($this->isModuleActive('cloud_security')) {
            $builder
                ->add('cloudControlReference', TextType::class, [
                    'label' => 'control.field.cloud_control_reference',
                    'required' => false,
                    'help' => 'control.help.iso_27017',
                    'attr' => ['maxlength' => 255],
                ])
                ->add('cloudPrivacyReference', TextType::class, [
                    'label' => 'control.field.cloud_privacy_reference',
                    'required' => false,
                    'help' => 'control.help.iso_27018',
                    'attr' => ['maxlength' => 255],
                ])
                ->add('pimsReference', TextType::class, [
                    'label' => 'control.field.pims_reference',
                    'required' => false,
                    'help' => 'control.help.iso_27701',
                    'attr' => ['maxlength' => 255],
                ])
                ->add('customerOrProviderResponsibility', ChoiceType::class, [
                    'choices' => [
                        'control.responsibility.customer' => 'customer',
                        'control.responsibility.provider' => 'provider',
                        'control.responsibility.shared' => 'shared',
                    ],
                    'choice_translation_domain' => 'control',
                    'label' => 'control.field.customer_or_provider_responsibility',
                    'required' => false,
                    'placeholder' => 'control.placeholder.responsibility',
                    'help' => 'control.help.shared_responsibility',
                ]);
        }

        // F10 — derive `category` from the ISO 27001:2022 Annex A control-id
        // prefix when the field is still empty. Soft pre-fill only: the user
        // can override the selection. No DB migration — the value lands on the
        // entity through the normal form-mapping when category was blank.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $control = $event->getData();
            if (!$control instanceof Control) {
                return;
            }
            if (trim((string) $control->getCategory()) !== '') {
                return;
            }
            $derived = self::deriveCategoryFromControlId($control->getControlId());
            if ($derived !== null) {
                $control->setCategory($derived);
            }
        });
    }

    /**
     * F10 — map an ISO 27001:2022 Annex A control-id to its theme/category.
     *
     * A.5.* → organizational, A.6.* → people, A.7.* → physical,
     * A.8.* → technological. Returns null for non-Annex-A ids so custom
     * frameworks are left untouched.
     */
    public static function deriveCategoryFromControlId(?string $controlId): ?string
    {
        if ($controlId === null) {
            return null;
        }
        if (!preg_match('/^A\.?(\d+)\./', trim($controlId), $m)) {
            return null;
        }

        return match ($m[1]) {
            '5' => 'organizational',
            '6' => 'people',
            '7' => 'physical',
            '8' => 'technological',
            default => null,
        };
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Control::class,
            'allow_control_id_edit' => false, // Default: Control ID kann nicht geändert werden
            'translation_domain' => 'control',
            // Junior-ISB-Audit-2026-05-22 S-07: wire the form root into the
            // `applicability-toggle` Stimulus controller so the visible
            // required-marker on `justification` follows the `applicable`
            // radio state (mirrors validateJustificationWhenNotApplicable).
            // ISO 27001 Cl. 6.1.3 d requires a written justification for
            // non-applicable controls — server enforces, this just communicates
            // the requirement before form submission.
            'attr' => [
                'data-controller' => 'applicability-toggle',
                'data-applicability-toggle-required-when-value' => 'false',
            ],
            'constraints' => [
                new Callback([$this, 'validateResponsibleSlot']),
                new Callback([$this, 'validateJustificationWhenNotApplicable']),
                new Callback([$this, 'validateVerifiedRequiresEvidence']),
                new Callback([$this, 'validateEffectivenessRequiresTest']),
                new Callback([$this, 'validateReviewDateOrder']),
            ],
        ]);
    }

    /**
     * SectionPolicy (S4 Foundation P-2) — groups the ~30 Control fields into
     * regulatorily-meaningful sections so the SoA edit form never dumps a
     * field into the generic catch-all. Section keys resolve to
     * `form.section.<key>` in the `messages` domain.
     *
     * Cloud fields are listed even though they are module-gated: when
     * `cloud_security` is inactive they simply are not added to the builder,
     * and the section-map references are tolerated by the renderer.
     *
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'overview' => ['controlId', 'name', 'description', 'category'],
            'applicability' => ['applicable', 'justification'],
            'implementation' => [
                'implementationStatus',
                'implementationPercentage',
                'implementationNotes',
                'targetDate',
            ],
            'classification' => ['controlType', 'automationLevel', 'controlMaturity'],
            'effectiveness' => [
                'effectiveness',
                'lastEffectivenessTest',
                'nextEffectivenessTest',
                'evidenceDocuments',
            ],
            'responsibility' => [
                'responsiblePersonUser',
                'responsiblePersonRef',
                'responsibleDeputyPersons',
                'responsiblePerson',
            ],
            'review' => ['lastReviewDate', 'nextReviewDate'],
            'references' => ['frameworkReferences'],
            'relations' => ['risks', 'protectedAssets'],
            'cloud' => [
                'cloudControlReference',
                'cloudPrivacyReference',
                'pimsReference',
                'customerOrProviderResponsibility',
            ],
        ];
    }

    public function validateResponsibleSlot(?Control $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getResponsiblePersonUser() === null && $entity->getResponsiblePersonRef() === null) {
            $context->buildViolation('control.error.owner_required_user_or_person')
                ->atPath('responsiblePersonUser')
                ->addViolation();
        }
    }

    /**
     * ISO 27001 6.1.3 d / 8.3 b — SoA must document a justification for every
     * non-applicable control. Junior-ISB-audit P0-01: the help-text says the
     * field is mandatory but the form previously allowed empty submissions.
     * This callback closes the help-vs-code gap.
     */
    public function validateJustificationWhenNotApplicable(?Control $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->isApplicable() === false && trim((string) $entity->getJustification()) === '') {
            $context->buildViolation('control.error.justification_required_when_not_applicable')
                ->atPath('justification')
                ->addViolation();
        }
    }

    /**
     * F4 + C — when a control is marked `verified` it must carry evidence of
     * verification:
     *   - a verification/review date (lastEffectivenessTest OR lastReviewDate),
     *   - an effectiveness rating other than `not_assessed`, AND
     *   - 100 % implementation (a verified control cannot be partially done).
     *
     * ISO 27001 Cl. 9.1 — controls claimed effective need monitoring evidence.
     */
    public function validateVerifiedRequiresEvidence(?Control $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null || $entity->getImplementationStatus() !== 'verified') {
            return;
        }

        if ($entity->getLastEffectivenessTest() === null && $entity->getLastReviewDate() === null) {
            $context->buildViolation('control.error.verified_requires_date')
                ->atPath('lastEffectivenessTest')
                ->addViolation();
        }

        if ($entity->getEffectiveness() === null || $entity->getEffectiveness() === 'not_assessed') {
            $context->buildViolation('control.error.verified_requires_effectiveness')
                ->atPath('effectiveness')
                ->addViolation();
        }

        if (($entity->getImplementationPercentage() ?? 0) < 100) {
            $context->buildViolation('control.error.verified_requires_full_percentage')
                ->atPath('implementationPercentage')
                ->addViolation();
        }
    }

    /**
     * F5 — an effectiveness rating other than `not_assessed` requires a
     * documented last-effectiveness-test date (ISO 27001 Cl. 9.1 — you cannot
     * rate effectiveness without having measured it).
     */
    public function validateEffectivenessRequiresTest(?Control $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        $effectiveness = $entity->getEffectiveness();
        if ($effectiveness === null || $effectiveness === 'not_assessed') {
            return;
        }
        if ($entity->getLastEffectivenessTest() === null) {
            $context->buildViolation('control.error.effectiveness_requires_test')
                ->atPath('lastEffectivenessTest')
                ->addViolation();
        }
    }

    /**
     * F11 — cross-field date sanity: a "next" date must be strictly after its
     * "last" counterpart (review schedule + effectiveness-test schedule).
     */
    public function validateReviewDateOrder(?Control $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }

        $lastReview = $entity->getLastReviewDate();
        $nextReview = $entity->getNextReviewDate();
        if ($lastReview !== null && $nextReview !== null && $nextReview <= $lastReview) {
            $context->buildViolation('control.error.next_review_after_last')
                ->atPath('nextReviewDate')
                ->addViolation();
        }

        $lastTest = $entity->getLastEffectivenessTest();
        $nextTest = $entity->getNextEffectivenessTest();
        if ($lastTest !== null && $nextTest !== null && $nextTest <= $lastTest) {
            $context->buildViolation('control.error.next_test_after_last')
                ->atPath('nextEffectivenessTest')
                ->addViolation();
        }
    }
}
