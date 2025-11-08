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
                'label' => 'Titel',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Verbesserung der Informationssicherheit'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Titel ein.']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Der Titel darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Detaillierte Beschreibung des Ziels'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine Beschreibung ein.'])
                ]
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Kategorie',
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
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie eine Kategorie aus.'])
                ]
            ])
            ->add('measurableIndicators', TextareaType::class, [
                'label' => 'Messbare Indikatoren',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Wie wird der Fortschritt gemessen? z.B. Anzahl durchgeführter Schulungen'
                ]
            ])
            ->add('targetValue', NumberType::class, [
                'label' => 'Zielwert',
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
                'label' => 'Aktueller Wert',
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
                'label' => 'Einheit',
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
                'label' => 'Verantwortliche Person',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name des Verantwortlichen'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine verantwortliche Person an.']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Name darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'Zieldatum',
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie ein Zieldatum an.'])
                ],
                'help' => 'Zieldatum für die Erreichung des Ziels'
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
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
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie einen Status aus.'])
                ]
            ])
            ->add('progressNotes', TextareaType::class, [
                'label' => 'Fortschrittsnotizen',
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
