<?php

declare(strict_types=1);

namespace App\Form;

use Doctrine\ORM\QueryBuilder;
use App\Entity\Asset;
use App\Entity\InternalAudit;
use App\Entity\ComplianceFramework;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\InternalAuditStatus;
use App\Repository\TenantRepository;
use App\Service\TenantContext;
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
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Junior-ISB-Audit-2026-05-22 Schicht-5: Module-Gate-Polish
        // Gate corporate-scope choices behind tenant's corporate-structure
        // membership. There is no dedicated "konzern" feature module — the
        // holding hierarchy is intrinsic to Tenant.parent / Tenant.subsidiaries.
        // ISO 19011 Cl. 5.5.2: audit scope must reflect actual organisational
        // reach; offering "corporate_wide" on a standalone tenant is misleading.
        $tenant = $this->tenantContext->getCurrentTenant();
        $isCorporate = $tenant !== null && $tenant->isPartOfCorporateStructure();

        $scopeTypeChoices = [
            'audit.scope_type.full_isms' => 'full_isms',
            'audit.scope_type.compliance_framework' => 'compliance_framework',
            'audit.scope_type.asset' => 'asset',
            'audit.scope_type.asset_type' => 'asset_type',
            'audit.scope_type.asset_group' => 'asset_group',
            'audit.scope_type.location' => 'location',
            'audit.scope_type.department' => 'department',
        ];
        if ($isCorporate) {
            $scopeTypeChoices['audit.scope_type.corporate_wide'] = 'corporate_wide';
            $scopeTypeChoices['audit.scope_type.corporate_subsidiaries'] = 'corporate_subsidiaries';
        }

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
                'choices' => $scopeTypeChoices,
                'attr' => [
                    'data-corporate-scope' => '1',
                    // C5-04 — Trigger id for the dependent `scopedAssets` field
                    // wrapped in conditional-fields controller. ISO 19011 Cl. 5.5.2.
                    'id' => 'internal_audit_scopeType',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
                'help' => 'audit.help.scope_type',
                    'choice_translation_domain' => 'audit',
            ])
            // C5-04 — Scoped Assets multi-select. Only visible when scopeType='asset'.
            // Toggle handled by the `conditional-fields` Stimulus controller; data-
            // attributes carry the trigger reference, the controller hides the entire
            // form-row wrapper when scopeType differs. ISO 19011 Cl. 5.5.2: audit
            // scope must list the audited objects (here: explicit Asset selection).
            ->add('scopedAssets', EntityType::class, [
                'label' => 'audit.field.scoped_assets',
                'class' => Asset::class,
                'choice_label' => fn(Asset $a): string => $a->getName() ?? '',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                    'data-depends-on' => 'internal_audit_scopeType',
                    'data-depends-on-value' => 'asset',
                ],
                'help' => 'audit.help.scoped_assets',
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
            // ── Status field is READ-ONLY (Lifecycle-bypass fix) ──────────────
            // Owned by `internal_audit_lifecycle`. YAML 4-eyes on `approve`.
            // Transitions via LifecycleService::transition() only.
            ->add('status', EnumType::class, [
                'label' => 'audit.field.status',
                'help' => 'audit.help.status_readonly',
                'class' => InternalAuditStatus::class,
                'choice_label' => fn(InternalAuditStatus $s): string => 'audit.status.' . $s->value,
                // Status is stored as VARCHAR (?string) on the entity; accept either
                // an enum case OR its raw string value so EnumType can resolve the
                // currently-selected option from both Doctrine hydration paths.
                'choice_value' => fn(InternalAuditStatus|string|null $c): ?string =>
                    $c instanceof InternalAuditStatus ? $c->value : $c,
                'required' => false,
                'disabled' => true,
                // mapped=false: entity status stays untouched regardless of POST value.
                // Status transitions are owned exclusively by LifecycleService.
                'mapped' => false,
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
            // Junior-ISB-Audit-2026-05-22 C2-04: Doppelpflege-Deprecation —
            // findings/recommendations/conclusion freetext fields are disabled.
            // Use AuditFinding entity (structuredFindings collection) instead.
            // Closes data-reuse violation per ISO 27001 Cl. 9.2 — structured
            // audit-findings with traceability to CAPAs.
            ->add('findings', TextareaType::class, [
                'label' => 'audit.field.findings',
                'required' => false,
                'disabled' => true,
                'attr' => [
                    'rows' => 6,
                    'readonly' => 'readonly',
                ],
                'help' => 'audit.help.findings',
            ])
            ->add('recommendations', TextareaType::class, [
                'label' => 'audit.field.recommendations',
                'required' => false,
                'disabled' => true,
                'attr' => [
                    'rows' => 4,
                    'readonly' => 'readonly',
                ],
                'help' => 'audit.help.recommendations',
            ])
            ->add('conclusion', TextareaType::class, [
                'label' => 'audit.field.conclusion',
                'required' => false,
                'disabled' => true,
                'attr' => [
                    'rows' => 4,
                    'readonly' => 'readonly',
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
