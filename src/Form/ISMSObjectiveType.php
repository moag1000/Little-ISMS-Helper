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
                    'placeholder' => 'z.B. Verbesserung der Informationssicherheit'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'objective.validation.title_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'objective.validation.title_max_length'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'objective.field.description',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Detaillierte Beschreibung des Ziels'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'objective.validation.description_required'])
                ]
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'objective.field.category',
                'choices' => [
                    'Verfügbarkeit' => 'availability',
                    'Vertraulichkeit' => 'confidentiality',
                    'Integrität' => 'integrity',
                    'Compliance' => 'compliance',
                    'Risikomanagement' => 'risk_management',
                    'Incident Response' => 'incident_response',
                    'Awareness & Schulung' => 'awareness',
                    'Kontinuierliche Verbesserung' => 'continual_improvement',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'objective.validation.category_required'])
                ]
            ])
            ->add('measurableIndicators', TextareaType::class, [
                'label' => 'objective.field.measurable_indicators',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Wie wird der Fortschritt gemessen? z.B. Anzahl durchgeführter Schulungen'
                ]
            ])
            ->add('targetValue', NumberType::class, [
                'label' => 'objective.field.target_value',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. 100',
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
                    'placeholder' => 'z.B. 75',
                    'step' => '0.01'
                ],
                'html5' => true,
                'scale' => 2
            ])
            ->add('unit', ChoiceType::class, [
                'label' => 'objective.field.unit',
                'required' => false,
                'choices' => [
                    'Prozent (%)' => '%',
                    'Tage' => 'days',
                    'Stunden' => 'hours',
                    'Anzahl' => 'count',
                    'Vorfälle' => 'incidents',
                    'Mitarbeiter' => 'employees',
                    'Euro (€)' => '€',
                    'Punkte' => 'points',
                ],
                'placeholder' => 'Einheit auswählen',
                'attr' => [
                    'class' => 'form-select'
                ]
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'objective.field.responsible_person',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name des Verantwortlichen'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'objective.validation.responsible_person_required']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'objective.validation.name_max_length'
                    ])
                ]
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'objective.field.target_date',
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'objective.validation.target_date_required'])
                ],
                'help' => 'Zieldatum für die Erreichung des Ziels'
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'objective.field.status',
                'choices' => [
                    'Nicht begonnen' => 'not_started',
                    'In Bearbeitung' => 'in_progress',
                    'Erreicht' => 'achieved',
                    'Verzögert' => 'delayed',
                    'Abgebrochen' => 'cancelled',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'objective.validation.status_required'])
                ]
            ])
            ->add('progressNotes', TextareaType::class, [
                'label' => 'objective.field.progress_notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Notizen zum aktuellen Fortschritt, Hindernisse, etc.'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ISMSObjective::class,
        ]);
    }
}
