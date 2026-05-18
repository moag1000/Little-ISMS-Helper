<?php

declare(strict_types=1);

namespace App\Form;

use Doctrine\ORM\QueryBuilder;
use App\Entity\InternalAudit;
use App\Entity\ComplianceFramework;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\InternalAuditStatus;
use App\Repository\TenantRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class InternalAuditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'audit.field.title',
                'attr' => ['placeholder' => 'audit.placeholder.title'],
                'constraints' => [
                    new NotBlank(message: 'audit.validation.title_required'),
                    new Length(min: 5, max: 255),
                ],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'audit.field.scope',
                'required' => false,
                'attr' => [
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
                    'rows' => 4,
                    'placeholder' => 'audit.placeholder.objectives',
                ],
                'help' => 'audit.help.objectives',
            ])
            ->add('plannedDate', DateType::class, [
                'label' => 'audit.field.planned_date',
                'widget' => 'single_text',
                'constraints' => [
                    new NotBlank(message: 'audit.validation.date_required'),
                ],
            ])
            ->add('actualDate', DateType::class, [
                'label' => 'audit.field.actual_date',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'audit.help.actual_date',
            ])
            ->add('status', EnumType::class, [
                'label' => 'audit.field.status',
                'class' => InternalAuditStatus::class,
                'choice_label' => fn(InternalAuditStatus $s): string => 'audit.status.' . $s->value,
                // Status is stored as VARCHAR (?string) on the entity; accept either
                // an enum case OR its raw string value so EnumType can resolve the
                // currently-selected option from both Doctrine hydration paths.
                'choice_value' => fn(InternalAuditStatus|string|null $c): ?string =>
                    $c instanceof InternalAuditStatus ? $c->value : $c,
                'choice_translation_domain' => 'audit',
            ])
            // P-15 DataReuse: Pattern A dual-state lead auditor — structured
            // User/Person preferred over legacy `leadAuditor` free-text. Legacy
            // field kept as optional textfield for migration window.
            ->add('leadAuditorUser', EntityType::class, [
                'label' => 'audit.field.lead_auditor_user',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder' => 'audit.placeholder.lead_auditor_user',
                'required' => false,
                'help' => 'audit.help.lead_auditor_user',
            ])
            ->add('leadAuditorPerson', EntityType::class, [
                'label' => 'audit.field.lead_auditor_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'audit.placeholder.lead_auditor_person',
                'required' => false,
                'help' => 'audit.help.lead_auditor_person',
            ])
            ->add('leadAuditor', TextType::class, [
                'label' => 'audit.field.lead_auditor_legacy',
                'required' => false,
                'attr' => [
                    'placeholder' => 'audit.placeholder.lead_auditor',
                ],
                'help' => 'audit.help.lead_auditor_legacy',
            ])
            ->add('auditTeamMembers', EntityType::class, [
                'label' => 'audit.field.audit_team_members',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'audit.help.audit_team_members',
            ])
            ->add('auditTeam', TextareaType::class, [
                'label' => 'audit.field.audit_team_legacy',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'audit.placeholder.audit_team',
                ],
                'help' => 'audit.help.audit_team_legacy',
            ])
            ->add('scopedFramework', EntityType::class, [
                'label' => 'audit.field.scoped_framework',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'placeholder' => 'audit.placeholder.scoped_framework',
                'required' => false,
                                'help' => 'audit.help.scoped_framework',
            ])
            ->add('additionalScopedFrameworks', EntityType::class, [
                'label' => 'audit.field.additional_scoped_frameworks',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'size' => 6,
                ],
                'help' => 'audit.help.additional_scoped_frameworks',
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
                    'size' => 8,
                    'data-corporate-subsidiaries' => '1',
                ],
                'help' => 'audit.help.audited_subsidiaries',
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'audit.field.findings',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                ],
                'help' => 'audit.help.findings',
            ])
            ->add('recommendations', TextareaType::class, [
                'label' => 'audit.field.recommendations',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'audit.help.recommendations',
            ])
            ->add('conclusion', TextareaType::class, [
                'label' => 'audit.field.conclusion',
                'required' => false,
                'attr' => [
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
            'constraints' => [
                new Callback([$this, 'validateLeadAuditorSlot']),
            ],
        ]);
    }

    /**
     * P-15 DataReuse: lead auditor must be provided in at least one of
     * the three Pattern-A states (User, Person, or legacy free-text). The
     * legacy column is no longer NotBlank in the entity to allow the
     * migration window — but at form level we still demand one of them.
     */
    public function validateLeadAuditorSlot(?InternalAudit $audit, ExecutionContextInterface $context): void
    {
        if ($audit === null) {
            return;
        }
        if ($audit->getLeadAuditorUser() !== null) {
            return;
        }
        if ($audit->getLeadAuditorPerson() !== null) {
            return;
        }
        $legacy = $audit->getLeadAuditor();
        if ($legacy !== null && trim($legacy) !== '') {
            return;
        }
        $context->buildViolation('audit.error.lead_auditor_required')
            ->atPath('leadAuditorUser')
            ->addViolation();
    }
}
