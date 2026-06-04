<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\ComplianceFramework;
use App\Entity\InternalAudit;
use App\Entity\Person;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see InternalAudit} (ISO 27001 Cl. 9.2 / ISO 19011
 * internal-audit records).
 *
 * The entity is not module-gated (`module: null`) — internal audits belong to
 * the always-on ISMS core, so the import is offered to every tenant.
 *
 * Field set mirrors the user-editable fields exposed by {@see \App\Form\InternalAuditType}:
 *   - single-select choice with a fixed value list → TYPE_ENUM (lowercased)
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (Person, ComplianceFramework) → TYPE_RELATION resolved by
 *     a human field that is itself a real #[ORM\Column] on the related entity
 *     (`Person.fullName`, `ComplianceFramework.name`)
 *
 * `auditNumber` is the unique upsert key (NotBlank on the entity). It is not
 * exposed by the FormType (auto-generated in the UI) but is the natural import
 * dedup key, mirroring how ProcessingActivity uses `name`. `title` and
 * `plannedDate` carry the entity's NotBlank/NotNull constraints and are flagged
 * required.
 *
 * `leadAuditor` is a genuine free-text VARCHAR (?string setter), NOT a relation —
 * the structured Pattern-A states (`leadAuditorUser`, `leadAuditorPerson`) are
 * the typed alternatives; only the Person one is imported (User has no plain
 * human lookup column, so it is excluded like ProcessingActivity excludes its
 * User relations).
 *
 * Excluded by design:
 *   - id, tenant, createdAt/updatedAt, lockVersion (infrastructure)
 *   - lifecycle-owned `status` (form field is disabled/mapped=false — owned by
 *     `internal_audit_lifecycle` via LifecycleService)
 *   - deprecated + disabled free-text `findings`, `recommendations`, `conclusion`
 *     (read-only in the form — use the AuditFinding structured collection)
 *   - M:N collections without a single human lookup field: scopedAssets,
 *     additionalScopedFrameworks, auditedSubsidiaries, auditTeamMembers
 *   - leadAuditorUser (User relation — no plain human lookup column)
 *   - JSON-structured scopeDetails, computed/derived accessors, and the
 *     approval-workflow audit-trail relations (reportedBy/approvedBy/closedBy…)
 */
final class InternalAuditImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'InternalAudit';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'InternalAudit',
            entityClass: InternalAudit::class,
            module: null,
            fields: [
                // ── Identification (unique upsert key) ──────────────────────────
                new ImportFieldSpec(
                    name: 'auditNumber',
                    setter: 'setAuditNumber',
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

                // ── Scope (ISO 19011 Cl. 5.5.2) ─────────────────────────────────
                new ImportFieldSpec(
                    name: 'scope',
                    setter: 'setScope',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'scopeType',
                    setter: 'setScopeType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'full_isms',
                        'compliance_framework',
                        'asset',
                        'asset_type',
                        'asset_group',
                        'location',
                        'department',
                        'corporate_wide',
                        'corporate_subsidiaries',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'objectives',
                    setter: 'setObjectives',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Schedule ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'plannedDate',
                    setter: 'setPlannedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'actualDate',
                    setter: 'setActualDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Lead auditor (legacy free-text) + team (legacy free-text) ───
                new ImportFieldSpec(
                    name: 'leadAuditor',
                    setter: 'setLeadAuditor',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'auditTeam',
                    setter: 'setAuditTeam',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Structured lead auditor (Person master-data FK) ─────────────
                new ImportFieldSpec(
                    name: 'leadAuditorPerson',
                    setter: 'setLeadAuditorPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),

                // ── Scoped framework (ComplianceFramework master-data FK) ────────
                new ImportFieldSpec(
                    name: 'scopedFramework',
                    setter: 'setScopedFramework',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: ComplianceFramework::class,
                    relationLookup: 'name',
                ),
            ],
        );
    }
}
