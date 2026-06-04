<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Person;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see CorrectiveAction} (ISO 27001 Clause 10.1 CAPA).
 *
 * The entity is not framework-gated (audit/CAPA is part of core ISMS), so the
 * schema declares no `module` at the {@see EntityImportSchema} level. The source
 * {@see \App\Form\CorrectiveActionType} carries no `isModuleActive`-wrapped
 * builder blocks either, so there are no per-field module gates.
 *
 * Field set mirrors the user-editable, mapped fields exposed by
 * CorrectiveActionType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text → TYPE_STRING; textarea → TYPE_TEXT
 *   - master-data FKs (AuditFinding, Person) → TYPE_RELATION resolved by a human field
 *
 * `title` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (description) are flagged required. `finding` is form-required, but the
 * entity column is nullable (a CA may be sourced from an Incident / manual /
 * change-request per CAPA-Canonical-Process), so it is imported as an optional
 * relation rather than a hard-required field.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/verifiedAt),
 * lockVersion, lifecycle-owned `status` (mapped=false in the form, owned by
 * `corrective_action_lifecycle`), source-tracking fields not on the form
 * (sourceType, sourceIncident, sourceChangeRequest, previousCapa, verifiedBy),
 * computed accessors (effectiveResponsiblePerson, isOverdue, …), the
 * `responsiblePersonUser` User relation (no human lookup field — only Person
 * relations are imported, mirroring the template convention), and M:N
 * collections without a human lookup (relatedControls, responsibleDeputyPersons).
 */
final class CorrectiveActionImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'CorrectiveAction';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'CorrectiveAction',
            entityClass: CorrectiveAction::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'finding',
                    setter: 'setFinding',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: AuditFinding::class,
                    relationLookup: 'findingNumber',
                ),
                new ImportFieldSpec(
                    name: 'title',
                    setter: 'setTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'actionType',
                    setter: 'setActionType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        CorrectiveAction::ACTION_TYPE_CORRECTIVE,
                        CorrectiveAction::ACTION_TYPE_PREVENTIVE,
                        CorrectiveAction::ACTION_TYPE_IMPROVEMENT,
                    ],
                ),

                // ── Root cause ──────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'rootCauseAnalysis',
                    setter: 'setRootCauseAnalysis',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Action plan ─────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'responsiblePerson',
                    setter: 'setResponsiblePerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'plannedCompletionDate',
                    setter: 'setPlannedCompletionDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'actualCompletionDate',
                    setter: 'setActualCompletionDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Effectiveness verification (Cl. 10.1) ───────────────────────
                new ImportFieldSpec(
                    name: 'effectivenessReviewDate',
                    setter: 'setEffectivenessReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'effectivenessNotes',
                    setter: 'setEffectivenessNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'effectivenessEvidence',
                    setter: 'setEffectivenessEvidence',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
