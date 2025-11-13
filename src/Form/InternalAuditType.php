<?php

namespace App\Form;

use App\Entity\InternalAudit;
use App\Entity\User;
use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
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
                'label' => 'audit.field.title',
                'attr' => ['class' => 'form-control', 'placeholder' => 'z.B. ISO 27001 Internes Audit Q1 2025'],
                'constraints' => [
                    new NotBlank(['message' => 'audit.validation.title_required']),
                    new Length(['min' => 5, 'max' => 255]),
                ],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'audit.field.scope',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Beschreiben Sie den Geltungsbereich des Audits...',
                ],
            ])
            ->add('scopeType', ChoiceType::class, [
                'label' => 'audit.field.scope_type',
                'choices' => [
                    'audit.scope_type.full_isms' => 'full_isms',
                    'audit.scope_type.compliance_framework' => 'compliance_framework',
                    'audit.scope_type.asset' => 'asset',
                    'audit.scope_type.asset_type' => 'asset_type',
                    'audit.scope_type.asset_group' => 'asset_group',
                    'audit.scope_type.location' => 'location',
                    'audit.scope_type.department' => 'department',
                    'audit.scope_type.corporate_wide' => 'corporate_wide',
                    'audit.scope_type.corporate_subsidiaries' => 'corporate_subsidiaries',
                ],
                'attr' => [
                    'class' => 'form-select',
                    'data-corporate-scope' => '1',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
                'help' => 'Für Konzernaudits wählen Sie "Konzernweites Audit" oder "Tochtergesellschafts-Audit"',
            ])
            ->add('objectives', TextareaType::class, [
                'label' => 'audit.field.objectives',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Welche Ziele sollen mit diesem Audit erreicht werden?',
                ],
                'help' => 'Definieren Sie klare, messbare Audit-Ziele.',
            ])
            ->add('plannedDate', DateType::class, [
                'label' => 'audit.field.planned_date',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'audit.validation.date_required']),
                ],
            ])
            ->add('actualDate', DateType::class, [
                'label' => 'audit.field.actual_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'Tatsächliches Durchführungsdatum des Audits.',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'audit.field.status',
                'choices' => [
                    'Geplant' => 'planned',
                    'In Durchführung' => 'in_progress',
                    'Abgeschlossen' => 'completed',
                    'Verschoben' => 'postponed',
                    'Abgebrochen' => 'cancelled',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('leadAuditor', TextType::class, [
                'label' => 'audit.field.lead_auditor',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name des Lead Auditors',
                ],
            ])
            ->add('auditTeam', TextareaType::class, [
                'label' => 'audit.field.audit_team',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Namen der Audit-Teammitglieder',
                ],
                'help' => 'Geben Sie die Namen der Teammitglieder ein.',
            ])
            ->add('scopedFramework', EntityType::class, [
                'label' => 'audit.field.scoped_framework',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'placeholder' => '-- Kein spezifisches Framework --',
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'Compliance-Framework für framework-spezifische Audits.',
            ])
            ->add('auditedSubsidiaries', EntityType::class, [
                'label' => 'audit.field.audited_subsidiaries',
                'class' => Tenant::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'query_builder' => function (TenantRepository $repo) {
                    return $repo->createQueryBuilder('t')
                        ->where('t.parent IS NOT NULL')
                        ->orderBy('t.name', 'ASC');
                },
                'attr' => [
                    'class' => 'form-select',
                    'size' => 8,
                    'data-corporate-subsidiaries' => '1',
                ],
                'help' => 'Wählen Sie die Tochtergesellschaften für dieses Konzernaudit aus (nur bei Corporate-Audits relevant)',
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'audit.field.findings',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                ],
                'help' => 'Dokumentieren Sie Findings, Nichtkonformitäten und Beobachtungen.',
            ])
            ->add('recommendations', TextareaType::class, [
                'label' => 'audit.field.recommendations',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Empfehlungen zur Verbesserung des ISMS.',
            ])
            ->add('conclusion', TextareaType::class, [
                'label' => 'audit.field.conclusion',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Kurze Zusammenfassung und Schlussfolgerungen der Audit-Ergebnisse.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InternalAudit::class,
        ]);
    }
}
