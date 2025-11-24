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
                    'placeholder' => 'z.B. A.5.1',
                    'readonly' => !$options['allow_control_id_edit'],
                ],
                'help' => 'ISO 27001:2022 Annex A Control ID',
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'control.field.name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Policies for information security',
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
                    'placeholder' => 'Begründen Sie die Anwendbarkeit oder Nicht-Anwendbarkeit...',
                ],
                'help' => 'Pflicht für ISO 27001 Statement of Applicability (SoA)',
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
                'help' => 'Implementierungsfortschritt in Prozent (0-100)',
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'control.field.implementation_notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Beschreiben Sie wie das Control implementiert ist.',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'control.field.responsible_person',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 100,
                    'placeholder' => 'Name der verantwortlichen Person',
                ],
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'control.field.target_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'Bis wann soll das Control vollständig implementiert sein?',
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
                'help' => 'Wann soll das Control das nächste Mal überprüft werden?',
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
                'help' => 'Welche Assets werden durch dieses Control geschützt?',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Control::class,
            'allow_control_id_edit' => false, // Default: Control ID kann nicht geändert werden
        ]);
    }
}
