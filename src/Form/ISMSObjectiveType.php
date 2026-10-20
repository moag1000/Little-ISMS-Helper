<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ISMSObjective;
use App\Form\SectionMapInterface;
use App\Form\Trait\OwnerPickerFormTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ISMSObjectiveType extends AbstractType implements SectionMapInterface
{
    use OwnerPickerFormTrait;

    public static function getSectionMap(): array
    {
        return [
            'overview'      => ['title', 'description', 'category'],
            'target_metric' => ['measurableIndicators', 'targetValue', 'currentValue', 'unit'],
            'monitoring'    => ['measurementFrequency', 'measurementMethod'],
            'responsibility'=> ['responsiblePersonUser', 'responsiblePersonRef', 'responsibleDeputyPersons', 'responsiblePerson', 'responsibleForMeasurement', 'targetDate'],
            'audit_metadata'=> ['status', 'progressNotes'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'objective.field.title',
                'attr' => [
                    'placeholder' => 'objective.placeholder.title'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.title_required'),
                    new Assert\Length(max: 255, maxMessage: 'objective.validation.title_max_length')
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'objective.field.description',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'objective.placeholder.description'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.description_required')
                ]
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'objective.field.category',
                'choices' => [
                    'objective.category.availability' => 'availability',
                    'objective.category.confidentiality' => 'confidentiality',
                    'objective.category.integrity' => 'integrity',
                    'objective.category.compliance' => 'compliance',
                    'objective.category.risk_management' => 'risk_management',
                    'objective.category.incident_response' => 'incident_response',
                    'objective.category.awareness' => 'awareness',
                    'objective.category.continual_improvement' => 'continual_improvement',
                ],
                                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.category_required')
                ],
                'choice_translation_domain' => 'objective',
            ])
            ->add('measurableIndicators', TextareaType::class, [
                'label' => 'objective.field.measurable_indicators',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'objective.placeholder.measurable_indicators'
                ]
            ])
            ->add('targetValue', NumberType::class, [
                'label' => 'objective.field.target_value',
                'required' => false,
                'attr' => [
                    'placeholder' => 'objective.placeholder.target_value',
                    'step' => '0.01'
                ],
                'html5' => true,
                'scale' => 2
            ])
            ->add('currentValue', NumberType::class, [
                'label' => 'objective.field.current_value',
                'required' => false,
                'attr' => [
                    'placeholder' => 'objective.placeholder.current_value',
                    'step' => '0.01'
                ],
                'html5' => true,
                'scale' => 2
            ])
            ->add('unit', ChoiceType::class, [
                'label' => 'objective.field.unit',
                'required' => false,
                'choices' => [
                    'objective.unit.percent' => '%',
                    'objective.unit.days' => 'days',
                    'objective.unit.hours' => 'hours',
                    'objective.unit.count' => 'count',
                    'objective.unit.incidents' => 'incidents',
                    'objective.unit.employees' => 'employees',
                    'objective.unit.euro' => '€',
                    'objective.unit.points' => 'points',
                ],
                'placeholder' => 'objective.placeholder.unit',
                                'choice_translation_domain' => 'objective',
            ]);

        // Pattern A dual-state owner cluster (User + Person + deputies) via the
        // shared trait; the legacy free-text field below keeps round-tripping.
        $this->addOwnerPicker($builder, [
            'translation_prefix' => 'objective',
            'user_field'         => 'responsiblePersonUser',
            'person_field'       => 'responsiblePersonRef',
            'deputies_field'     => 'responsibleDeputyPersons',
            'user_label'         => 'objective.field.responsible_person_user',
            'person_label'       => 'objective.field.responsible_person_ref',
            'deputies_label'     => 'objective.field.responsible_deputies',
            'user_placeholder'   => 'objective.placeholder.responsible_person_user',
            'person_placeholder' => 'objective.placeholder.responsible_person_ref',
            'user_help'          => 'objective.help.responsible_person_user',
            'person_help'        => 'objective.help.responsible_person_ref',
            'deputies_help'      => 'objective.help.responsible_deputies',
        ]);

        $builder
            ->add('responsiblePerson', TextType::class, [
                'label' => 'objective.field.responsible_person_legacy',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'objective.placeholder.responsible_person',
                ],
            ])
            ->add('responsibleForMeasurement', TextType::class, [
                'label' => 'objective.field.responsible_for_measurement',
                'required' => false,
                'help' => 'objective.help.responsible_for_measurement',
                                'constraints' => [
                    new Assert\Length(max: 100, maxMessage: 'objective.validation.name_max_length')
                ],
            ])
            ->add('measurementFrequency', ChoiceType::class, [
                'label' => 'objective.field.measurement_frequency',
                'required' => false,
                'placeholder' => 'objective.placeholder.measurement_frequency',
                'help' => 'objective.help.measurement_frequency',
                'choices' => [
                    'objective.frequency.daily' => 'daily',
                    'objective.frequency.weekly' => 'weekly',
                    'objective.frequency.monthly' => 'monthly',
                    'objective.frequency.quarterly' => 'quarterly',
                    'objective.frequency.biannually' => 'biannually',
                    'objective.frequency.annually' => 'annually',
                    'objective.frequency.on_event' => 'on_event',
                ],
            ])
            ->add('measurementMethod', TextareaType::class, [
                'label' => 'objective.field.measurement_method',
                'required' => false,
                'help' => 'objective.help.measurement_method',
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'objective.placeholder.measurement_method',
                ],
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'objective.field.target_date',
                'widget' => 'single_text',
                                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.target_date_required')
                ],
                'help' => 'objective.help.target_date'
            ])
            // ── Status field is READ-ONLY (Lifecycle-bypass fix) ──────────────
            // Owned by `isms_objective_lifecycle`. Transitions via
            // LifecycleService::transition() only.
            ->add('status', ChoiceType::class, [
                'label' => 'objective.field.status',
                'help' => 'objective.help.status_readonly',
                'choices' => [
                    'objective.status.not_started' => 'not_started',
                    'objective.status.in_progress' => 'in_progress',
                    'objective.status.achieved' => 'achieved',
                    'objective.status.delayed' => 'delayed',
                    'objective.status.cancelled' => 'cancelled',
                ],
                'required' => false,
                'disabled' => true,
                // mapped=false: entity status stays untouched regardless of POST value.
                // Status transitions are owned exclusively by LifecycleService.
                'mapped' => false,
                'choice_translation_domain' => 'objective',
            ])
            ->add('progressNotes', TextareaType::class, [
                'label' => 'objective.field.progress_notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'objective.placeholder.progress_notes'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ISMSObjective::class,
            'translation_domain' => 'objective',
            'constraints' => [
                new Callback([$this, 'validateResponsibleSlot']),
            ],
        ]);
    }

    public function validateResponsibleSlot(?ISMSObjective $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getResponsiblePersonUser() === null
            && $entity->getResponsiblePersonRef() === null
            && trim((string) $entity->getResponsiblePerson()) === '') {
            $context->buildViolation('objective.error.owner_required')
                ->atPath('responsiblePersonUser')
                ->addViolation();
        }
    }
}
