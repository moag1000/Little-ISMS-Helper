<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Patch;
use App\Entity\Vulnerability;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Patch} (NIS2 Art. 21.2.e — vulnerability handling,
 * ISO 27001 A.8.8 patch / vulnerability management).
 *
 * Field set mirrors the user-editable fields exposed by {@see \App\Form\PatchType}:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea / URL → TYPE_STRING / TYPE_TEXT
 *   - master-data FK (Vulnerability) → TYPE_RELATION resolved by a real human column
 *
 * `patchId` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (title, description, vendor, product, releaseDate) are flagged required.
 * `requiresDowntime` / `requiresReboot` are required in the form (expanded
 * yes/no with no empty option) and flagged accordingly.
 *
 * The entity is not import-gated by a compliance module — neither PatchType nor
 * its options carry a module gate — so {@see EntityImportSchema::$module} is null
 * and no per-field module gate is declared.
 *
 * The Vulnerability relation is resolved by `cveId`, a real unique
 * `#[ORM\Column]` on {@see Vulnerability} (the form's choice_label is a computed
 * "cveId - title" concat, which is NOT a column and therefore cannot be a
 * relationLookup; the CVE id is the stable human key).
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lockVersion,
 * lifecycle-owned `status` (mapped=false in the form, owned by patch_lifecycle),
 * computed accessors (isOverdue, getPriorityBadgeClass, getDaysUntilDeadline,
 * calculateDeploymentDeadline), the `dependencies` JSON field (not exposed by the
 * form), and the `affectedAssets` M:N collection (multi-relation, no single human
 * lookup).
 */
final class PatchImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Patch';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Patch',
            entityClass: Patch::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'patchId',
                    setter: 'setPatchId',
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
                new ImportFieldSpec(
                    name: 'patchType',
                    setter: 'setPatchType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['security', 'critical', 'feature', 'bugfix', 'hotfix'],
                ),
                new ImportFieldSpec(
                    name: 'priority',
                    setter: 'setPriority',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['critical', 'high', 'medium', 'low'],
                ),

                // ── Vulnerability link / vendor info ────────────────────────────
                new ImportFieldSpec(
                    name: 'vulnerability',
                    setter: 'setVulnerability',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Vulnerability::class,
                    relationLookup: 'cveId',
                ),
                new ImportFieldSpec(
                    name: 'vendor',
                    setter: 'setVendor',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'product',
                    setter: 'setProduct',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'version',
                    setter: 'setVersion',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'downloadUrl',
                    setter: 'setDownloadUrl',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'documentationUrl',
                    setter: 'setDocumentationUrl',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Testing ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'testingNotes',
                    setter: 'setTestingNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'requiresDowntime',
                    setter: 'setRequiresDowntime',
                    type: ImportFieldSpec::TYPE_BOOL,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'estimatedDowntimeMinutes',
                    setter: 'setEstimatedDowntimeMinutes',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'requiresReboot',
                    setter: 'setRequiresReboot',
                    type: ImportFieldSpec::TYPE_BOOL,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'knownIssues',
                    setter: 'setKnownIssues',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Rollout ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'releaseDate',
                    setter: 'setReleaseDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'deploymentDeadline',
                    setter: 'setDeploymentDeadline',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'deployedDate',
                    setter: 'setDeployedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'responsiblePerson',
                    setter: 'setResponsiblePerson',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'deploymentNotes',
                    setter: 'setDeploymentNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Verification ────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'rollbackPlan',
                    setter: 'setRollbackPlan',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
