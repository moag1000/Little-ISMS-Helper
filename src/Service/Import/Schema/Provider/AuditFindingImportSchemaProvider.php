<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\InternalAudit;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see AuditFinding} (ISO 27001 Clause 10.1 — structured
 * audit findings: nonconformities, observations, opportunities for improvement).
 *
 * Field set mirrors the user-editable fields exposed by {@see \App\Form\AuditFindingType}:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - DateType/DateTimeType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (InternalAudit, Control) → TYPE_RELATION resolved by a human field
 *
 * `findingNumber` is the unique upsert key (NotBlank on the entity). The other
 * NotBlank fields (title, description) are flagged required.
 *
 * The entity is not module-gated (audit findings belong to the always-on audit
 * domain), so {@see EntityImportSchema::$module} is null and no per-field gate
 * is declared (the source FormType carries no module-conditional builder blocks).
 *
 * Excluded by design: id, tenant, audit-trail timestamps (createdAt, closedAt),
 * lockVersion, lifecycle-owned `status` (transitions via LifecycleService only),
 * computed fields (isOverdue / effective* accessors), the structured CAPA JSON
 * field `nonconformityDetails` (JsonStructuredType builder, no flat import shape),
 * and M:N collections without a single human lookup (relatedControls plural,
 * reportedByDeputyPersons, assignedDeputyPersons, linkedRequirements,
 * correctiveActions). The Owner cluster (assignedTo / assignedPerson) and
 * reporter cluster (reportedBy / reportedByPerson) are User-/Person-relations
 * without a stable flat lookup in this schema and are left to the editing UI;
 * only the legacy singular `relatedControl` FK and the required `audit` FK are
 * imported as relations.
 */
final class AuditFindingImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'AuditFinding';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'AuditFinding',
            entityClass: AuditFinding::class,
            module: null,
            fields: [
                // ── Parent audit (required relation, Art. 30(1) equivalent) ─────
                new ImportFieldSpec(
                    name: 'audit',
                    setter: 'setAudit',
                    type: ImportFieldSpec::TYPE_RELATION,
                    required: true,
                    relationClass: InternalAudit::class,
                    relationLookup: 'auditNumber',
                ),

                // ── Overview (ISO 27001 Cl. 10.1) ───────────────────────────────
                new ImportFieldSpec(
                    name: 'findingNumber',
                    setter: 'setFindingNumber',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'title',
                    setter: 'setTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),

                // ── Classification ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'type',
                    setter: 'setType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        AuditFinding::TYPE_MAJOR_NC,
                        AuditFinding::TYPE_MINOR_NC,
                        AuditFinding::TYPE_OBSERVATION,
                        AuditFinding::TYPE_OPPORTUNITY,
                    ],
                ),
                new ImportFieldSpec(
                    name: 'severity',
                    setter: 'setSeverity',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        AuditFinding::SEVERITY_CRITICAL,
                        AuditFinding::SEVERITY_HIGH,
                        AuditFinding::SEVERITY_MEDIUM,
                        AuditFinding::SEVERITY_LOW,
                    ],
                ),
                new ImportFieldSpec(
                    name: 'source',
                    setter: 'setSource',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        AuditFinding::SOURCE_INTERNAL_AUDIT,
                        AuditFinding::SOURCE_EXTERNAL_AUDIT,
                        AuditFinding::SOURCE_INCIDENT,
                        AuditFinding::SOURCE_REVIEW,
                        AuditFinding::SOURCE_CUSTOMER_COMPLAINT,
                        AuditFinding::SOURCE_MANAGEMENT_REVIEW,
                    ],
                ),
                new ImportFieldSpec(
                    name: 'clauseReference',
                    setter: 'setClauseReference',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Root cause / evidence ───────────────────────────────────────
                new ImportFieldSpec(
                    name: 'evidence',
                    setter: 'setEvidence',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'relatedControl',
                    setter: 'setRelatedControl',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Control::class,
                    relationLookup: 'controlId',
                ),

                // ── Corrective action ───────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'dueDate',
                    setter: 'setDueDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── S17 B4 — CAPA / NC details (ISO 27001 Cl. 10.2 b) + d)) ─────
                new ImportFieldSpec(
                    name: 'ncRootCauseSummary',
                    setter: 'setNcRootCauseSummary',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'ncCorrectionDueDate',
                    setter: 'setNcCorrectionDueDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'ncVerifiedAt',
                    setter: 'setNcVerifiedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
            ],
        );
    }
}
