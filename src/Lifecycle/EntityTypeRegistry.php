<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Maps URL slugs (e.g. "document") to entity FQCN + workflow name.
 *
 * Registered entities include Document, ProcessingActivity, ISMSObjective,
 * PolicyTemplate, Asset (custom physical lifecycle: 7 places, 9 transitions)
 * and custom-stage entities such as AuditFinding, Consent, CorrectiveAction,
 * DataBreach, DataSubjectRequest, DPIA, Incident, InternalAudit, Risk and
 * Vulnerability. The MAP constant below is the authoritative list.
 */
final class EntityTypeRegistry
{
    /** @var array<string, array{class: class-string, workflow: string, module: string|null}> */
    private const array MAP = [
        'document' => [
            'class' => \App\Entity\Document::class,
            'workflow' => 'document_lifecycle',
            'module' => null,
        ],
        'processing-activity' => [
            'class' => \App\Entity\ProcessingActivity::class,
            'workflow' => 'processing_activity_lifecycle',
            'module' => 'privacy',
        ],
        'isms-objective' => [
            'class' => \App\Entity\ISMSObjective::class,
            'workflow' => 'isms_objective_lifecycle',
            'module' => null,
        ],
        'policy-template' => [
            'class' => \App\Entity\PolicyTemplate::class,
            'workflow' => 'policy_template_lifecycle',
            'module' => null,
        ],
        'asset' => [
            'class' => \App\Entity\Asset::class,
            'workflow' => 'asset_lifecycle',
            'module' => 'assets',
        ],
        'audit-finding' => [
            'class' => \App\Entity\AuditFinding::class,
            'workflow' => 'audit_finding_lifecycle',
            'module' => 'audits',
        ],

        // AuditProgram ISO 19011 §5.4 programme lifecycle.
        'audit-program' => [
            'class' => \App\Entity\AuditProgram::class,
            'workflow' => 'audit_program_lifecycle',
            'module' => 'audits',
        ],
        'consent' => [
            'class' => \App\Entity\Consent::class,
            'workflow' => 'consent_lifecycle',
            'module' => 'privacy',
        ],
        'corrective-action' => [
            'class' => \App\Entity\CorrectiveAction::class,
            'workflow' => 'corrective_action_lifecycle',
            'module' => 'corrective_actions',
        ],
        'data-breach' => [
            'class' => \App\Entity\DataBreach::class,
            'workflow' => 'data_breach_lifecycle',
            'module' => 'privacy',
        ],
        'data-subject-request' => [
            'class' => \App\Entity\DataSubjectRequest::class,
            'workflow' => 'data_subject_request_lifecycle',
            'module' => 'privacy',
        ],
        'dpia' => [
            'class' => \App\Entity\DataProtectionImpactAssessment::class,
            'workflow' => 'dpia_lifecycle',
            'module' => 'privacy',
        ],
        'incident' => [
            'class' => \App\Entity\Incident::class,
            'workflow' => 'incident_lifecycle',
            'module' => 'incidents',
        ],
        'internal-audit' => [
            'class' => \App\Entity\InternalAudit::class,
            'workflow' => 'internal_audit_lifecycle',
            'module' => 'audits',
        ],
        'risk' => [
            'class' => \App\Entity\Risk::class,
            'workflow' => 'risk_lifecycle',
            'module' => 'risks',
        ],
        'vulnerability' => [
            'class' => \App\Entity\Vulnerability::class,
            'workflow' => 'vulnerability_lifecycle',
            'module' => 'vulnerability_intel',
        ],
        // WorkflowInstance approval-chain state-machine
        'workflow-instance' => [
            'class' => \App\Entity\WorkflowInstance::class,
            'workflow' => 'workflow_instance_lifecycle',
            'module' => null,
        ],
        // 10 additional entity lifecycles closing the FormType-bypass surface.
        // See ADR 2026-05-18-formtype-status-hijack-and-lifecycle-extension.
        'training' => [
            'class' => \App\Entity\Training::class,
            'workflow' => 'training_lifecycle',
            'module' => 'training',
        ],
        'risk-treatment-plan' => [
            'class' => \App\Entity\RiskTreatmentPlan::class,
            'workflow' => 'risk_treatment_plan_lifecycle',
            'module' => 'risk_treatment_plans',
        ],
        'supplier' => [
            'class' => \App\Entity\Supplier::class,
            'workflow' => 'supplier_lifecycle',
            'module' => 'suppliers',
        ],
        'prototype-protection-assessment' => [
            'class' => \App\Entity\PrototypeProtectionAssessment::class,
            'workflow' => 'prototype_protection_assessment_lifecycle',
            'module' => 'tisax',
        ],
        'business-continuity-plan' => [
            'class' => \App\Entity\BusinessContinuityPlan::class,
            'workflow' => 'business_continuity_plan_lifecycle',
            'module' => 'bcm',
        ],
        'patch' => [
            'class' => \App\Entity\Patch::class,
            'workflow' => 'patch_lifecycle',
            'module' => 'patches',
        ],
        'management-review' => [
            'class' => \App\Entity\ManagementReview::class,
            'workflow' => 'management_review_lifecycle',
            'module' => 'reviews',
        ],
        // Top-management decisions (ISO 27001 5.1 / 9.3.3, NIS-2 Art. 20).
        'management-decision' => [
            'class' => \App\Entity\ManagementDecision::class,
            'workflow' => 'management_decision_lifecycle',
            'module' => null,
        ],
        'change-request' => [
            'class' => \App\Entity\ChangeRequest::class,
            'workflow' => 'change_request_lifecycle',
            'module' => 'change_requests',
        ],
        'threat-intelligence' => [
            'class' => \App\Entity\ThreatIntelligence::class,
            'workflow' => 'threat_intelligence_lifecycle',
            'module' => 'vulnerability_intel',
        ],
        'bc-exercise' => [
            'class' => \App\Entity\BCExercise::class,
            'workflow' => 'bc_exercise_lifecycle',
            'module' => 'bcm',
        ],
        // InterestedParty lifecycle
        // (ISO 27001 Cl. 4.2 + 9.3.2 c — Mgmt-Review-Input demands versioning).
        'interested-party' => [
            'class' => \App\Entity\InterestedParty::class,
            'workflow' => 'interested_party_lifecycle',
            'module' => 'interested_parties',
        ],
        // NotificationDelivery 6-stage
        // delivery pipeline (ISO 27001 Cl. 7.4 + DORA Art. 19).
        'notification-delivery' => [
            'class' => \App\Entity\Notification\NotificationDelivery::class,
            'workflow' => 'notification_delivery_lifecycle',
            'module' => null,
        ],
        // RBAC core entities.
        // Permission catalog (ISO 27001 A.5.15-A.5.16) — 4 stages.
        'permission' => [
            'class' => \App\Entity\Permission::class,
            'workflow' => 'permission_lifecycle',
            'module' => null,
        ],
        // Role catalog (ISO 27001 A.5.15-A.5.18, segregation-of-duties) —
        // 3 stages, 4-eyes on archive.
        'role' => [
            'class' => \App\Entity\Role::class,
            'workflow' => 'role_lifecycle',
            'module' => null,
        ],
        // Tenant (security-critical,
        // 30+ isActive callsites preserved via wrapper). 5-stage lifecycle:
        // draft → active ⇄ suspended → terminated → archived.
        'tenant' => [
            'class' => \App\Entity\Tenant::class,
            'workflow' => 'tenant_lifecycle',
            'module' => null,
        ],
        // Lifecycle completion — staged-status entities that still set their
        // status directly. Policy section sign-off (GDPR Art. 38(3)).
        'document-section' => [
            'class' => \App\Entity\DocumentSection::class,
            'workflow' => 'document_section_lifecycle',
            'module' => null,
        ],
        // GDPR Art. 46 / Schrems II transfer impact assessment.
        'transfer-impact-assessment' => [
            'class' => \App\Entity\TransferImpactAssessment::class,
            'workflow' => 'transfer_impact_assessment_lifecycle',
            'module' => 'privacy',
        ],
        // DORA Art. 26 threat-led penetration test.
        'threat-led-penetration-test' => [
            'class' => \App\Entity\ThreatLedPenetrationTest::class,
            'workflow' => 'threat_led_penetration_test_lifecycle',
            'module' => 'nis2_dora',
        ],
        // Outbound supplier security questionnaire (ISO 27001 A.5.19-A.5.22).
        'supplier-questionnaire' => [
            'class' => \App\Entity\SupplierQuestionnaire::class,
            'workflow' => 'supplier_questionnaire_lifecycle',
            'module' => 'suppliers',
        ],
        // Access review campaign (ISO 27001 A.5.18).
        'access-review-campaign' => [
            'class' => \App\Entity\AccessReviewCampaign::class,
            'workflow' => 'access_review_campaign_lifecycle',
            'module' => 'access_review',
        ],
    ];

    /** @return array{class: class-string, workflow: string, module: string|null}|null */
    public function lookup(string $slug): ?array
    {
        return self::MAP[$slug] ?? null;
    }

    /**
     * S35: the module that owns a lifecycle type (null = a module that cannot be switched off). One source for the
     * generic lifecycle endpoint, the workflow lists and the activity feed; a module switch must act on every path.
     */
    public function moduleOf(string $slug): ?string
    {
        return self::MAP[$slug]['module'] ?? null;
    }

    /**
     * Module owning a record class, by FQCN or short name ("DataBreach"); null when none or unknown.
     * Where two lifecycle types share a class the first one wins (they share the module too).
     */
    public static function moduleOfClass(string $classOrShortName): ?string
    {
        $short = (string) preg_replace('#^.*\\\\#', '', $classOrShortName);
        foreach (self::MAP as $entry) {
            if (preg_replace('#^.*\\\\#', '', $entry['class']) === $short) {
                return $entry['module'];
            }
        }

        return null;
    }

    /** @return string[] */
    public function knownSlugs(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Every entity class whose status is owned by a lifecycle workflow.
     * Single source of truth for tooling such as the PHPStan rule
     * NoDirectSetStatusRule, which must not keep its own copy.
     *
     * @return list<class-string>
     */
    public static function managedEntityClasses(): array
    {
        return array_values(array_unique(array_column(self::MAP, 'class')));
    }

    /**
     * Whether an organisation may override this workflow's transition metadata
     * (roles, four-eyes, reason, module) in /admin/lifecycle-overrides.
     *
     * Only workflows of organisation-owned records: a workflow of an instance
     * record (Tenant, Role, Permission, PolicyTemplate, global compliance
     * mappings) acts on every organisation, so an organisation admin who
     * lowered its roles could suspend another organisation or archive a role
     * for everyone. Derived from the entity class, so a new global entity is
     * covered without a list.
     */
    public static function acceptsTenantOverrides(string $workflowName): bool
    {
        foreach (self::MAP as $entry) {
            if ($entry['workflow'] === $workflowName) {
                return method_exists($entry['class'], 'getTenant');
            }
        }

        return false;
    }
}
