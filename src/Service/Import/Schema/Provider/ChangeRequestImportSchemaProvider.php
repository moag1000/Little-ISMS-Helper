<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\AuditFinding;
use App\Entity\ChangeRequest;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see ChangeRequest} (ISO 27001 A.8.32 · ITIL change
 * management records).
 *
 * The entity is part of the always-on ISMS core (no compliance-module gate on
 * {@see \App\Form\ChangeRequestType}), so the schema declares `module: null` at
 * the {@see EntityImportSchema} level and carries no per-field module gates.
 *
 * Field set mirrors the user-editable fields exposed by ChangeRequestType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - DateType single_text fields → TYPE_DATE
 *   - short free-text → TYPE_STRING / long textarea → TYPE_TEXT
 *   - the AuditFinding lineage FK → TYPE_RELATION resolved by `findingNumber`
 *
 * `title` is the unique upsert key (NotBlank on the entity). The other NotBlank
 * fields (changeNumber, changeType, description, justification, requestedBy,
 * requestedDate, priority) are flagged required.
 *
 * Excluded by design:
 *   - id, tenant, createdAt/updatedAt timestamps, lockVersion
 *   - lifecycle-owned `status` (form field is disabled + mapped=false; owned by
 *     `change_request_lifecycle` via LifecycleService::transition())
 *   - computed getters (complexityScore, workflowProgress, statusBadge, …)
 *   - M:N collections without a human lookup (affectedAssets, affectedControls,
 *     affectedProcesses, associatedRisks, documents)
 *   - `relatedCorrectiveAction`: the CorrectiveAction relation has no human
 *     lookup column (its form label is ID-based; no settable unique #[ORM\Column]),
 *     so SchemaDrivenMapper::resolveRelation() could not resolve it.
 */
final class ChangeRequestImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'ChangeRequest';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'ChangeRequest',
            entityClass: ChangeRequest::class,
            module: null,
            fields: [
                // ── Overview (ISO 27001 A.8.32) ─────────────────────────────────
                new ImportFieldSpec(
                    name: 'changeNumber',
                    setter: 'setChangeNumber',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'title',
                    setter: 'setTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'changeType',
                    setter: 'setChangeType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'isms_policy',
                        'isms_scope',
                        'control',
                        'asset',
                        'process',
                        'technology',
                        'supplier',
                        'organizational',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'justification',
                    setter: 'setJustification',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),

                // ── Details ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'requestedBy',
                    setter: 'setRequestedBy',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'requestedDate',
                    setter: 'setRequestedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'priority',
                    setter: 'setPriority',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['critical', 'high', 'medium', 'low'],
                ),
                new ImportFieldSpec(
                    name: 'clauseReference',
                    setter: 'setClauseReference',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Lineage (ISO 27001 Cl. 10.1 — upstream traceability) ────────
                new ImportFieldSpec(
                    name: 'relatedFinding',
                    setter: 'setRelatedFinding',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: AuditFinding::class,
                    relationLookup: 'findingNumber',
                ),

                // ── Impact assessment ───────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'ismsImpact',
                    setter: 'setIsmsImpact',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'riskAssessment',
                    setter: 'setRiskAssessment',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Implementation ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'implementationPlan',
                    setter: 'setImplementationPlan',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'rollbackPlan',
                    setter: 'setRollbackPlan',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'testingRequirements',
                    setter: 'setTestingRequirements',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'plannedImplementationDate',
                    setter: 'setPlannedImplementationDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'actualImplementationDate',
                    setter: 'setActualImplementationDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'implementedBy',
                    setter: 'setImplementedBy',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'implementationNotes',
                    setter: 'setImplementationNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Approval ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'approvedBy',
                    setter: 'setApprovedBy',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'approvedDate',
                    setter: 'setApprovedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'approvalComments',
                    setter: 'setApprovalComments',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Verification & closure ──────────────────────────────────────
                new ImportFieldSpec(
                    name: 'verifiedBy',
                    setter: 'setVerifiedBy',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'verifiedDate',
                    setter: 'setVerifiedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'verificationResults',
                    setter: 'setVerificationResults',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'closedDate',
                    setter: 'setClosedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'closureNotes',
                    setter: 'setClosureNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
