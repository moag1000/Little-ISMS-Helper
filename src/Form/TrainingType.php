<?php

namespace App\Form;

use App\Entity\Training;
use App\Entity\User;
use App\Entity\Control;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class TrainingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Schulungstitel',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. ISO 27001 Awareness Training',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte geben Sie einen Titel ein.']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Beschreiben Sie Inhalt und Ziele der Schulung...',
                ],
            ])
            ->add('trainingType', ChoiceType::class, [
                'label' => 'Schulungsart',
                'choices' => [
                    'Security Awareness' => 'security_awareness',
                    'Technisches Training' => 'technical',
                    'Compliance Training' => 'compliance',
                    'Notfallübung' => 'emergency_drill',
                    'Phishing-Simulation' => 'phishing_simulation',
                    'Data Protection' => 'data_protection',
                    'Cyber Security' => 'cyber_security',
                    'Sonstiges' => 'other',
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('deliveryMethod', ChoiceType::class, [
                'label' => 'Durchführungsart',
                'choices' => [
                    'Präsenz' => 'in_person',
                    'Online (Live)' => 'online_live',
                    'E-Learning (Selbststudium)' => 'e_learning',
                    'Hybrid' => 'hybrid',
                    'Workshop' => 'workshop',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('scheduledDate', DateTimeType::class, [
                'label' => 'Geplantes Datum & Uhrzeit',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte wählen Sie ein Datum.']),
                ],
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'Dauer (Minuten)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 15,
                    'placeholder' => 'z.B. 60',
                ],
                'constraints' => [
                    new Range(['min' => 15, 'max' => 480]),
                ],
                'help' => 'Dauer in Minuten (15-480)',
            ])
            ->add('location', TextType::class, [
                'label' => 'Ort / Meeting-Link',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Konferenzraum A oder Zoom-Link',
                ],
            ])
            ->add('trainer', EntityType::class, [
                'label' => 'Trainer / Durchführende Person',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'placeholder' => '-- Bitte wählen --',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('targetAudience', ChoiceType::class, [
                'label' => 'Zielgruppe',
                'choices' => [
                    'Alle Mitarbeiter' => 'all_employees',
                    'IT-Abteilung' => 'it_department',
                    'Management' => 'management',
                    'Entwickler' => 'developers',
                    'HR' => 'hr',
                    'Externe Dienstleister' => 'contractors',
                    'Neue Mitarbeiter' => 'new_employees',
                    'Spezifische Abteilungen' => 'specific_departments',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('participants', EntityType::class, [
                'label' => 'Teilnehmer',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getDepartment() . ')';
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 8,
                ],
                'help' => 'STRG gedrückt halten um mehrere Teilnehmer auszuwählen.',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Geplant' => 'planned',
                    'Bestätigt' => 'confirmed',
                    'Durchgeführt' => 'completed',
                    'Abgesagt' => 'cancelled',
                    'Verschoben' => 'postponed',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('mandatory', ChoiceType::class, [
                'label' => 'Verpflichtend',
                'choices' => [
                    'Ja, verpflichtend' => true,
                    'Nein, optional' => false,
                ],
                'expanded' => true,
                'data' => true,
            ])
            ->add('relatedControls', EntityType::class, [
                'label' => 'Verknüpfte Controls',
                'class' => Control::class,
                'choice_label' => function (Control $control) {
                    return $control->getControlId() . ' - ' . $control->getName();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
                'help' => 'Welche ISO 27001 Controls werden durch diese Schulung adressiert?',
            ])
            ->add('materials', TextareaType::class, [
                'label' => 'Schulungsmaterialien',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Links oder Beschreibungen von Schulungsmaterialien.',
            ])
            ->add('feedback', TextareaType::class, [
                'label' => 'Feedback / Notizen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Feedback von Teilnehmern oder Trainer-Notizen.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Training::class,
        ]);
    }
}
