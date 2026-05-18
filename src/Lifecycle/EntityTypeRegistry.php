<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Maps URL slugs (e.g. "document") to entity FQCN + workflow name.
 *
 * Foundation pilot (X.0) shipped the Document mapping.
 * Sprint X.1 adds ProcessingActivity and ISMSObjective.
 * Lifecycle unblock adds PolicyTemplate (previously deferred — lacked status field).
 * Sprint X.2 adds Asset — custom physical lifecycle (7 places, 9 transitions).
 * Sprint X.2 batch adds 10 custom-stage entities (AuditFinding, Consent,
 * CorrectiveAction, DataBreach, DataSubjectRequest, DPIA, Incident,
 * InternalAudit, Risk, Vulnerability).
 * Sprint Y.5 PR-A adds 4 compliance-critical entities (ChangeRequest, Patch,
 * ManagementReview, RiskTreatmentPlan).
 */
final class EntityTypeRegistry
{
    /** @var array<string, array{class: class-string, workflow: string}> */
    private const array MAP = [
        'document' => [
            'class' => \App\Entity\Document::class,
            'workflow' => 'document_lifecycle',
        ],
        'processing-activity' => [
            'class' => \App\Entity\ProcessingActivity::class,
            'workflow' => 'processing_activity_lifecycle',
        ],
        'isms-objective' => [
            'class' => \App\Entity\ISMSObjective::class,
            'workflow' => 'isms_objective_lifecycle',
        ],
        'policy-template' => [
            'class' => \App\Entity\PolicyTemplate::class,
            'workflow' => 'policy_template_lifecycle',
        ],
        'asset' => [
            'class' => \App\Entity\Asset::class,
            'workflow' => 'asset_lifecycle',
        ],
        'audit-finding' => [
            'class' => \App\Entity\AuditFinding::class,
            'workflow' => 'audit_finding_lifecycle',
        ],
        'consent' => [
            'class' => \App\Entity\Consent::class,
            'workflow' => 'consent_lifecycle',
        ],
        'corrective-action' => [
            'class' => \App\Entity\CorrectiveAction::class,
            'workflow' => 'corrective_action_lifecycle',
        ],
        'data-breach' => [
            'class' => \App\Entity\DataBreach::class,
            'workflow' => 'data_breach_lifecycle',
        ],
        'data-subject-request' => [
            'class' => \App\Entity\DataSubjectRequest::class,
            'workflow' => 'data_subject_request_lifecycle',
        ],
        'dpia' => [
            'class' => \App\Entity\DataProtectionImpactAssessment::class,
            'workflow' => 'dpia_lifecycle',
        ],
        'incident' => [
            'class' => \App\Entity\Incident::class,
            'workflow' => 'incident_lifecycle',
        ],
        'internal-audit' => [
            'class' => \App\Entity\InternalAudit::class,
            'workflow' => 'internal_audit_lifecycle',
        ],
        'risk' => [
            'class' => \App\Entity\Risk::class,
            'workflow' => 'risk_lifecycle',
        ],
        'vulnerability' => [
            'class' => \App\Entity\Vulnerability::class,
            'workflow' => 'vulnerability_lifecycle',
        ],
        // Sprint Y.0 — WorkflowInstance approval-chain state-machine
        'workflow-instance' => [
            'class' => \App\Entity\WorkflowInstance::class,
            'workflow' => 'workflow_instance_lifecycle',
        ],
        // Sprint Y.5 PR-A — Compliance-critical lifecycle extension
        'change-request' => [
            'class' => \App\Entity\ChangeRequest::class,
            'workflow' => 'change_request_lifecycle',
        ],
        'patch' => [
            'class' => \App\Entity\Patch::class,
            'workflow' => 'patch_lifecycle',
        ],
        'management-review' => [
            'class' => \App\Entity\ManagementReview::class,
            'workflow' => 'management_review_lifecycle',
        ],
        'risk-treatment-plan' => [
            'class' => \App\Entity\RiskTreatmentPlan::class,
            'workflow' => 'risk_treatment_plan_lifecycle',
        ],
    ];

    /** @return array{class: class-string, workflow: string}|null */
    public function lookup(string $slug): ?array
    {
        return self::MAP[$slug] ?? null;
    }

    /** @return string[] */
    public function knownSlugs(): array
    {
        return array_keys(self::MAP);
    }
}
