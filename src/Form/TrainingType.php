<?php

namespace App\Form;

use App\Entity\Training;
use App\Entity\User;
use App\Entity\Control;
use App\Entity\ComplianceRequirement;
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
                'label' => 'training.field.title',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. ISO 27001 Awareness Training',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'training.validation.title_required']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'training.field.description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Beschreiben Sie Inhalt und Ziele der Schulung...',
                ],
            ])
            ->add('trainingType', ChoiceType::class, [
                'label' => 'training.field.training_type',
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
                'label' => 'training.field.delivery_method',
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
                'label' => 'training.field.scheduled_date',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'training.validation.date_required']),
                ],
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'training.field.duration',
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
                'label' => 'training.field.location',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Konferenzraum A oder Zoom-Link',
                ],
            ])
            ->add('trainer', EntityType::class, [
                'label' => 'training.field.trainer',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'placeholder' => '-- Bitte wählen --',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('targetAudience', ChoiceType::class, [
                'label' => 'training.field.target_audience',
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
                'label' => 'training.field.participants',
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
                'label' => 'training.field.status',
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
                'label' => 'training.field.mandatory',
                'choices' => [
                    'Ja, verpflichtend' => true,
                    'Nein, optional' => false,
                ],
                'expanded' => true,
                'data' => true,
            ])
            ->add('coveredControls', EntityType::class, [
                'label' => 'training.field.covered_controls',
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
            ->add('complianceRequirements', EntityType::class, [
                'label' => 'training.field.compliance_requirements',
                'class' => ComplianceRequirement::class,
                'choice_label' => function (ComplianceRequirement $requirement) {
                    $framework = $requirement->getFramework();
                    $frameworkName = $framework ? $framework->getName() : 'N/A';
                    return $frameworkName . ' - ' . $requirement->getRequirementId() . ': ' . $requirement->getTitle();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
                'help' => 'Welche Compliance-Anforderungen werden durch diese Schulung erfüllt? (z.B. DORA, TISAX, NIS2)',
            ])
            ->add('materials', TextareaType::class, [
                'label' => 'training.field.materials',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Links oder Beschreibungen von Schulungsmaterialien.',
            ])
            ->add('feedback', TextareaType::class, [
                'label' => 'training.field.feedback',
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
