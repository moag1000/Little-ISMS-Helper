<?php

namespace App\Form;

use App\Entity\Control;
use App\Entity\Asset;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class ControlType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('controlId', TextType::class, [
                'label' => 'control.field.control_id',
                'attr' => [
                    'class' => 'form-control',
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
                    'class' => 'form-control',
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
                    'class' => 'form-control',
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
                'choice_translation_domain' => 'controls',
                'attr' => ['class' => 'form-select'],
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
                'choice_translation_domain' => 'controls',
                'expanded' => true,
                'attr' => ['class' => 'form-check'],
            ])
            ->add('justification', TextareaType::class, [
                'label' => 'control.field.justification',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
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
                'choice_translation_domain' => 'controls',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('implementationPercentage', IntegerType::class, [
                'label' => 'control.field.implementation_percentage',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 100,
                ],
                'constraints' => [
                    new Range(['min' => 0, 'max' => 100]),
                ],
                'help' => 'control.help.implementation_percentage',
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'control.field.implementation_notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'control.help.implementation_notes',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'control.field.responsible_person',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 100,
                    'placeholder' => 'control.placeholder.responsible_person',
                ],
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'control.field.target_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'control.help.target_date',
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'control.field.last_review_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'control.field.next_review_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'control.help.next_review_date',
            ])
            ->add('protectedAssets', EntityType::class, [
                'label' => 'control.field.protected_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
                'help' => 'control.help.protected_assets',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Control::class,
            'allow_control_id_edit' => false, // Default: Control ID kann nicht geändert werden
            'translation_domain' => 'control',
        ]);
    }
}
