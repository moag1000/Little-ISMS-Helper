<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\ISMSContext;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see ISMSContext} (ISO 27001 Cl. 4 — context of the
 * organization, scope, internal/external issues, interested parties).
 *
 * The entity is part of the core ISMS surface and is not module-gated, so the
 * schema declares no {@see EntityImportSchema} module and none of its fields
 * carry a per-field module gate (the source {@see \App\Form\ISMSContextType}
 * has no `isModuleActive`-wrapped builder blocks).
 *
 * Field set mirrors the user-editable fields exposed by ISMSContextType:
 *   - `organizationName` → TYPE_STRING (TextType, NotBlank) — required + unique
 *     upsert key (it is a length-255 #[ORM\Column], the only constrained scalar)
 *   - all remaining textarea fields → TYPE_TEXT (free-text TEXT columns)
 *   - the two DateType single_text fields → TYPE_DATE
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), and the
 * legacy `interestedParties` free-text column (no longer surfaced by the form —
 * interested parties are maintained in the structured /interested-party module).
 */
final class ISMSContextImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'ISMSContext';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'ISMSContext',
            entityClass: ISMSContext::class,
            module: null,
            fields: [
                // ── Overview (ISO 27001 Cl. 4.1 / 4.3) ──────────────────────────
                new ImportFieldSpec(
                    name: 'organizationName',
                    setter: 'setOrganizationName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),

                // ── Scope definition (Cl. 4.3) ──────────────────────────────────
                new ImportFieldSpec(
                    name: 'ismsScope',
                    setter: 'setIsmsScope',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'scopeExclusions',
                    setter: 'setScopeExclusions',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── External / internal issues (Cl. 4.1) ────────────────────────
                new ImportFieldSpec(
                    name: 'externalIssues',
                    setter: 'setExternalIssues',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'internalIssues',
                    setter: 'setInternalIssues',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Interested parties requirements (Cl. 4.2) ───────────────────
                new ImportFieldSpec(
                    name: 'interestedPartiesRequirements',
                    setter: 'setInterestedPartiesRequirements',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Legal / regulatory / contractual obligations ────────────────
                new ImportFieldSpec(
                    name: 'legalRequirements',
                    setter: 'setLegalRequirements',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'regulatoryRequirements',
                    setter: 'setRegulatoryRequirements',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'contractualObligations',
                    setter: 'setContractualObligations',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── ISMS policy & responsibilities (Cl. 5) ──────────────────────
                new ImportFieldSpec(
                    name: 'ismsPolicy',
                    setter: 'setIsmsPolicy',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'rolesAndResponsibilities',
                    setter: 'setRolesAndResponsibilities',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Review schedule (audit metadata) ────────────────────────────
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
            ],
        );
    }
}
