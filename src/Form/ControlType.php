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
                'label' => 'Control-ID',
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
                'label' => 'Control-Name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Policies for information security',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Kategorie',
                'choices' => [
                    'Organizational' => 'organizational',
                    'People' => 'people',
                    'Physical' => 'physical',
                    'Technological' => 'technological',
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('applicable', ChoiceType::class, [
                'label' => 'Anwendbarkeit',
                'choices' => [
                    'Anwendbar' => true,
                    'Nicht anwendbar' => false,
                ],
                'expanded' => true,
                'attr' => ['class' => 'form-check'],
            ])
            ->add('justification', TextareaType::class, [
                'label' => 'Begründung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Begründen Sie die Anwendbarkeit oder Nicht-Anwendbarkeit...',
                ],
                'help' => 'Pflicht für ISO 27001 Statement of Applicability (SoA)',
            ])
            ->add('implementationStatus', ChoiceType::class, [
                'label' => 'Implementierungsstatus',
                'choices' => [
                    'Nicht begonnen' => 'not_started',
                    'In Planung' => 'planned',
                    'In Umsetzung' => 'in_progress',
                    'Implementiert' => 'implemented',
                    'Nicht anwendbar' => 'not_applicable',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('implementationPercentage', IntegerType::class, [
                'label' => 'Fortschritt (%)',
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
                'label' => 'Implementierungsdetails',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Beschreiben Sie wie das Control implementiert ist.',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'Verantwortliche Person',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 100,
                    'placeholder' => 'Name der verantwortlichen Person',
                ],
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'Zieldatum',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'Bis wann soll das Control vollständig implementiert sein?',
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'Letztes Review',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'Nächstes Review',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'Wann soll das Control das nächste Mal überprüft werden?',
            ])
            ->add('protectedAssets', EntityType::class, [
                'label' => 'Verknüpfte Assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
                'help' => 'Welche Assets werden durch dieses Control geschützt?',
            ])
            ->add('evidenceDescription', TextareaType::class, [
                'label' => 'Nachweisdokumentation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Links oder Verweise auf Nachweisdokumente.',
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
