<?php

namespace App\Form;

use App\Entity\ISMSObjective;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ISMSObjectiveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'objective.field.title',
                'attr' => [
                    'class' => 'form-control',
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
                    'class' => 'form-control',
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
                'attr' => [
                    'class' => 'form-select'
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
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'objective.placeholder.measurable_indicators'
                ]
            ])
            ->add('targetValue', NumberType::class, [
                'label' => 'objective.field.target_value',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
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
                    'class' => 'form-control',
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
                'attr' => [
                    'class' => 'form-select'
                ],
                'choice_translation_domain' => 'objective',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'objective.field.responsible_person',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'objective.placeholder.responsible_person'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.responsible_person_required'),
                    new Assert\Length(max: 100, maxMessage: 'objective.validation.name_max_length')
                ]
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'objective.field.target_date',
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.target_date_required')
                ],
                'help' => 'objective.help.target_date'
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'objective.field.status',
                'choices' => [
                    'objective.status.not_started' => 'not_started',
                    'objective.status.in_progress' => 'in_progress',
                    'objective.status.achieved' => 'achieved',
                    'objective.status.delayed' => 'delayed',
                    'objective.status.cancelled' => 'cancelled',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'objective.validation.status_required')
                ],
                    'choice_translation_domain' => 'objective',
            ])
            ->add('progressNotes', TextareaType::class, [
                'label' => 'objective.field.progress_notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
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
        ]);
    }
}
