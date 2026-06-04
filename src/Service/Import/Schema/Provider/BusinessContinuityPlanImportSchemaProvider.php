<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Person;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see BusinessContinuityPlan} (ISO 22301 BC plans).
 *
 * Field set mirrors the user-editable fields exposed by
 * {@see \App\Form\BusinessContinuityPlanType}:
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - IntegerType recovery targets (rto/rpo) → TYPE_INT
 *   - DateType single_text fields → TYPE_DATE
 *   - master-data FKs (BusinessProcess, Person) → TYPE_RELATION resolved by a
 *     human field that is itself a real `#[ORM\Column]`
 *
 * `name` is the unique upsert key (NotBlank on the entity). Other entity-level
 * NotBlank / form-required fields (businessProcess, activationCriteria,
 * recoveryProcedures, rto, rpo, version) are flagged required.
 *
 * The entity is NOT module-gated: although it lives under the BCM domain, the
 * `bcm` module in config/modules.yaml lists only `BusinessProcess` in its
 * `entities` array — BusinessContinuityPlan is not enumerated there — so the
 * schema declares `module: null` at the {@see EntityImportSchema} level. No
 * per-field module gates exist (the source FormType carries no DORA/NIS2/GDPR
 * conditional blocks).
 *
 * Excluded by design:
 *   - id, tenant, createdAt/updatedAt timestamps, lockVersion (`#[ORM\Version]`)
 *   - lifecycle-owned `status` (Form input is mapped=false; transitions are
 *     owned exclusively by LifecycleService / business_continuity_plan_lifecycle)
 *   - deprecated `bcTeam` (Form input is `disabled` — superseded by
 *     responseTeamMembers; @deprecated since S13)
 *   - JSON-structured builder fields (responseTeamMembers, requiredResources,
 *     escalationLevels, responseTeam, stakeholderContacts) — no scalar/list
 *     round-trip
 *   - M:N collections without a single human lookup (criticalAssets,
 *     criticalSuppliers, documents, crisisTeams, planOwnerDeputyPersons)
 *   - User relation planOwnerUser (no human lookup field — only Person
 *     relations are imported, mirroring the ProcessingActivity convention)
 *   - computed/derived accessors (readinessScore, completenessPercentage,
 *     bsiPhaseLabel, effective/all plan owners)
 *
 * Legacy free-text `planOwner` (the OwnerPicker legacy slot) is imported as a
 * plain string for backward compatibility with flat source files.
 */
final class BusinessContinuityPlanImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'BusinessContinuityPlan';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'BusinessContinuityPlan',
            entityClass: BusinessContinuityPlan::class,
            module: null,
            fields: [
                // ── Basic information ───────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'name',
                    setter: 'setName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'businessProcess',
                    setter: 'setBusinessProcess',
                    type: ImportFieldSpec::TYPE_RELATION,
                    required: true,
                    relationClass: BusinessProcess::class,
                    relationLookup: 'name',
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Activation & responsibilities ───────────────────────────────
                new ImportFieldSpec(
                    name: 'activationCriteria',
                    setter: 'setActivationCriteria',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'rolesAndResponsibilities',
                    setter: 'setRolesAndResponsibilities',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'recoveryProcedures',
                    setter: 'setRecoveryProcedures',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),

                // ── Recovery targets (ISO 22301 Cl. 8.2.2) ──────────────────────
                new ImportFieldSpec(
                    name: 'rto',
                    setter: 'setRto',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'rpo',
                    setter: 'setRpo',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),

                // ── Communication ───────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'communicationPlan',
                    setter: 'setCommunicationPlan',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'internalCommunication',
                    setter: 'setInternalCommunication',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'externalCommunication',
                    setter: 'setExternalCommunication',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Alternative site & recovery infrastructure ──────────────────
                new ImportFieldSpec(
                    name: 'alternativeSite',
                    setter: 'setAlternativeSite',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'alternativeSiteAddress',
                    setter: 'setAlternativeSiteAddress',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'alternativeSiteCapacity',
                    setter: 'setAlternativeSiteCapacity',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'backupProcedures',
                    setter: 'setBackupProcedures',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'restoreProcedures',
                    setter: 'setRestoreProcedures',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Versioning ──────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'version',
                    setter: 'setVersion',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),

                // ── Test & review schedule ──────────────────────────────────────
                new ImportFieldSpec(
                    name: 'lastTested',
                    setter: 'setLastTested',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'nextTestDate',
                    setter: 'setNextTestDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'lastReviewDate',
                    setter: 'setLastReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'nextReviewDate',
                    setter: 'setNextReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'reviewNotes',
                    setter: 'setReviewNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Plan owner (OwnerPicker — Person FK + legacy free-text) ──────
                new ImportFieldSpec(
                    name: 'planOwnerPerson',
                    setter: 'setPlanOwnerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'planOwner',
                    setter: 'setPlanOwner',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
            ],
        );
    }
}
