<?php

namespace App\Form;

use Doctrine\ORM\QueryBuilder;
use App\Entity\InternalAudit;
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
                'attr' => ['class' => 'form-control', 'placeholder' => 'audit.placeholder.title'],
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
                    'placeholder' => 'audit.placeholder.scope',
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
                'help' => 'audit.help.scope_type',
                    'choice_translation_domain' => 'audit',
            ])
            ->add('objectives', TextareaType::class, [
                'label' => 'audit.field.objectives',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'audit.placeholder.objectives',
                ],
                'help' => 'audit.help.objectives',
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
                'help' => 'audit.help.actual_date',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'audit.field.status',
                'choices' => [
                    'audit.status.planned' => 'planned',
                    'audit.status.in_progress' => 'in_progress',
                    'audit.status.completed' => 'completed',
                    'audit.status.postponed' => 'postponed',
                    'audit.status.cancelled' => 'cancelled',
                ],
                'attr' => ['class' => 'form-select'],
                    'choice_translation_domain' => 'audit',
            ])
            ->add('leadAuditor', TextType::class, [
                'label' => 'audit.field.lead_auditor',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'audit.placeholder.lead_auditor',
                ],
            ])
            ->add('auditTeam', TextareaType::class, [
                'label' => 'audit.field.audit_team',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'audit.placeholder.audit_team',
                ],
                'help' => 'audit.help.audit_team',
            ])
            ->add('scopedFramework', EntityType::class, [
                'label' => 'audit.field.scoped_framework',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'placeholder' => 'audit.placeholder.scoped_framework',
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'audit.help.scoped_framework',
            ])
            ->add('auditedSubsidiaries', EntityType::class, [
                'label' => 'audit.field.audited_subsidiaries',
                'class' => Tenant::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'query_builder' => fn(TenantRepository $tenantRepository): QueryBuilder => $tenantRepository->createQueryBuilder('t')
                    ->where('t.parent IS NOT NULL')
                    ->orderBy('t.name', 'ASC'),
                'attr' => [
                    'class' => 'form-select',
                    'size' => 8,
                    'data-corporate-subsidiaries' => '1',
                ],
                'help' => 'audit.help.audited_subsidiaries',
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'audit.field.findings',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                ],
                'help' => 'audit.help.findings',
            ])
            ->add('recommendations', TextareaType::class, [
                'label' => 'audit.field.recommendations',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'audit.help.recommendations',
            ])
            ->add('conclusion', TextareaType::class, [
                'label' => 'audit.field.conclusion',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'audit.help.conclusion',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InternalAudit::class,
            'translation_domain' => 'audit',
        ]);
    }
}
