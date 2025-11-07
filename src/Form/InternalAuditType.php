<?php

namespace App\Form;

use App\Entity\InternalAudit;
use App\Entity\User;
use App\Entity\ComplianceFramework;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

class InternalAuditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Audit-Titel',
                'attr' => ['class' => 'form-control', 'placeholder' => 'z.B. ISO 27001 Internes Audit Q1 2025'],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte geben Sie einen Titel ein.']),
                    new Length(['min' => 5, 'max' => 255]),
                ],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'Geltungsbereich',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Beschreiben Sie den Geltungsbereich des Audits...',
                ],
            ])
            ->add('scopeType', ChoiceType::class, [
                'label' => 'Audit-Typ',
                'choices' => [
                    'Vollständiges System-Audit' => 'full',
                    'Prozess-spezifisch' => 'process',
                    'Standort-spezifisch' => 'location',
                    'Abteilungs-spezifisch' => 'department',
                    'Framework-spezifisch' => 'framework',
                    'Asset-spezifisch' => 'asset',
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('objectives', TextareaType::class, [
                'label' => 'Audit-Ziele',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Welche Ziele sollen mit diesem Audit erreicht werden?',
                ],
                'help' => 'Definieren Sie klare, messbare Audit-Ziele.',
            ])
            ->add('plannedDate', DateType::class, [
                'label' => 'Geplantes Datum',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte wählen Sie ein Datum.']),
                ],
            ])
            ->add('completedDate', DateType::class, [
                'label' => 'Abschlussdatum',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'Wird automatisch gesetzt wenn das Audit abgeschlossen wird.',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Geplant' => 'planned',
                    'In Durchführung' => 'in_progress',
                    'Abgeschlossen' => 'completed',
                    'Verschoben' => 'postponed',
                    'Abgebrochen' => 'cancelled',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('leadAuditor', EntityType::class, [
                'label' => 'Lead Auditor',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getEmail() . ')';
                },
                'placeholder' => '-- Bitte wählen --',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('auditTeam', EntityType::class, [
                'label' => 'Audit-Team',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
                'help' => 'Halten Sie STRG gedrückt um mehrere Teammitglieder auszuwählen.',
            ])
            ->add('frameworks', EntityType::class, [
                'label' => 'Geprüfte Frameworks',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 3,
                ],
                'help' => 'Welche Compliance-Frameworks werden in diesem Audit geprüft?',
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'Feststellungen & Nichtkonformitäten',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                ],
                'help' => 'Dokumentieren Sie Findings, Nichtkonformitäten und Beobachtungen.',
            ])
            ->add('recommendations', TextareaType::class, [
                'label' => 'Empfehlungen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Empfehlungen zur Verbesserung des ISMS.',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Zusammenfassung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Kurze Zusammenfassung der Audit-Ergebnisse.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InternalAudit::class,
        ]);
    }
}
