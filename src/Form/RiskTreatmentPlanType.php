<?php

namespace App\Form;

use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RiskTreatmentPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('risk', EntityType::class, [
                'label' => 'risk_treatment_plan.field.risk',
                'class' => Risk::class,
                'choice_label' => 'title',
                'placeholder' => 'risk_treatment_plan.placeholder.risk',
                'required' => true,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'risk_treatment_plan.help.risk',
                'constraints' => [
                    new Assert\NotNull(['message' => 'risk_treatment_plan.validation.risk_required'])
                ],
                'choice_translation_domain' => 'risk',
            ])
            ->add('title', TextType::class, [
                'label' => 'risk_treatment_plan.field.title',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 255,
                    'placeholder' => 'risk_treatment_plan.placeholder.title'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'risk_treatment_plan.validation.title_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'risk_treatment_plan.validation.title_max_length'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'risk_treatment_plan.field.description',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'risk_treatment_plan.placeholder.description'
                ],
                'help' => 'risk_treatment_plan.help.description',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'risk_treatment_plan.validation.description_required'])
                ]
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'risk_treatment_plan.field.status',
                'choices' => [
                    'risk_treatment_plan.status.planned' => 'planned',
                    'risk_treatment_plan.status.in_progress' => 'in_progress',
                    'risk_treatment_plan.status.completed' => 'completed',
                    'risk_treatment_plan.status.cancelled' => 'cancelled',
                    'risk_treatment_plan.status.on_hold' => 'on_hold',
                ],
                'required' => true,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'risk_treatment_plan.help.status',
                'choice_translation_domain' => 'risk_treatment_plan',
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'risk_treatment_plan.field.priority',
                'choices' => [
                    'risk_treatment_plan.priority.low' => 'low',
                    'risk_treatment_plan.priority.medium' => 'medium',
                    'risk_treatment_plan.priority.high' => 'high',
                    'risk_treatment_plan.priority.critical' => 'critical',
                ],
                'required' => true,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'risk_treatment_plan.help.priority',
                    'choice_translation_domain' => 'risk_treatment_plan',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'risk_treatment_plan.field.start_date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'risk_treatment_plan.help.start_date'
            ])
            ->add('targetCompletionDate', DateType::class, [
                'label' => 'risk_treatment_plan.field.target_completion_date',
                'widget' => 'single_text',
                'required' => true,
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'risk_treatment_plan.help.target_completion_date',
                'constraints' => [
                    new Assert\NotNull(['message' => 'risk_treatment_plan.validation.target_completion_date_required'])
                ]
            ])
            ->add('actualCompletionDate', DateType::class, [
                'label' => 'risk_treatment_plan.field.actual_completion_date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'risk_treatment_plan.help.actual_completion_date'
            ])
            ->add('budget', NumberType::class, [
                'label' => 'risk_treatment_plan.field.budget',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => '0.00'
                ],
                'help' => 'risk_treatment_plan.help.budget',
                'constraints' => [
                    new Assert\PositiveOrZero(['message' => 'risk_treatment_plan.validation.budget_positive'])
                ]
            ])
            ->add('responsiblePerson', EntityType::class, [
                'label' => 'risk_treatment_plan.field.responsible_person',
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getFullName() . ' (' . $user->getEmail() . ')';
                },
                'placeholder' => 'risk_treatment_plan.placeholder.responsible_person',
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'risk_treatment_plan.help.responsible_person'
            ])
            ->add('controls', EntityType::class, [
                'label' => 'risk_treatment_plan.field.controls',
                'class' => Control::class,
                'choice_label' => function(Control $control) {
                    return $control->getControlId() . ': ' . $control->getName();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 8
                ],
                'help' => 'risk_treatment_plan.help.controls'
            ])
            ->add('completionPercentage', IntegerType::class, [
                'label' => 'risk_treatment_plan.field.completion_percentage',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 100,
                    'placeholder' => '0-100'
                ],
                'help' => 'risk_treatment_plan.help.completion_percentage',
                'constraints' => [
                    new Assert\Range([
                        'min' => 0,
                        'max' => 100,
                        'notInRangeMessage' => 'risk_treatment_plan.validation.completion_percentage_range'
                    ])
                ]
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'risk_treatment_plan.field.implementation_notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'risk_treatment_plan.placeholder.implementation_notes'
                ],
                'help' => 'risk_treatment_plan.help.implementation_notes'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RiskTreatmentPlan::class,
            'translation_domain' => 'risk_treatment_plan',
        ]);
    }
}
