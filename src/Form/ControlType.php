<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Control;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\User;
use App\Entity\Asset;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Form\Type\JsonStructuredType;
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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ControlType extends AbstractType
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
            ->add('applicable', ChoiceType::class, [
                'label' => 'control.field.applicable',
                'choices' => [
                    'control.applicable.yes' => true,
                    'control.applicable.no' => false,
                ],
                'choice_translation_domain' => 'control',
                'expanded' => true,
                'attr' => ['class' => 'form-check'],
                'help' => 'control.help.applicable_explained',
            ])
            ->add('justification', TextareaType::class, [
                'label' => 'control.field.justification',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'control.placeholder.justification',
                ],
                'help' => 'control.help.justification',
            ])
            ->add('implementationStatus', ChoiceType::class, [
                'label' => 'control.field.implementation_status',
                'choices' => [
                    'control.implementation_status.not_started' => 'not_started',
                    'control.implementation_status.planned' => 'planned',
                    'control.implementation_status.in_progress' => 'in_progress',
                    'control.implementation_status.implemented' => 'implemented',
                    'control.implementation_status.verified' => 'verified',
                ],
                'choice_translation_domain' => 'control',
                'help' => 'control.help.implementation_status_explained',
            ])
            ->add('implementationPercentage', IntegerType::class, [
                'label' => 'control.field.implementation_percentage',
                'attr' => [
                    'min' => 0,
                    'max' => 100,
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
            // TODO(s5-json-objects): replace with structured map editor
            // (shape: {iso27001:[...], bsi:[...], nist:[...], dora:[...]}).
            // C-06: JsonStructuredType applies JsonArrayTransformer automatically.
            ->add('frameworkReferences', JsonStructuredType::class, [
                'label' => 'control.field.framework_references',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'control.help.framework_references_json',
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

        // JsonArrayTransformer now applied automatically by JsonStructuredType.

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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Control::class,
            'allow_control_id_edit' => false, // Default: Control ID kann nicht geändert werden
            'translation_domain' => 'control',
            'constraints' => [
                new Callback([$this, 'validateResponsibleSlot']),
                new Callback([$this, 'validateJustificationWhenNotApplicable']),
            ],
        ]);
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
}
