<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Asset;
use App\Entity\AssetSubType;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\User;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for the {@see Asset} entity (ISO 27001 A.5.9 — Asset Inventory).
 *
 * Asset is a CORE entity — the whole import is NOT module-gated
 * ({@see EntityImportSchema::$module} = null). Individual fields are gated per
 * the source {@see \App\Form\AssetType} module conditionals:
 *   - `isDoraRelevant`                  → `nis2_dora` (DORA Art. 28 ICT-register flag)
 *   - `tisaxInformationClassification`  → `tisax`     (VDA-ISA 6.0 overlay)
 *   - all `aiAgent*` fields             → `ai_governance` (EU AI Act / ISO 42001)
 *
 * Field set mirrors the user-editable ->add() calls of AssetType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - integer / json-tags fields → TYPE_INT / TYPE_LIST
 *   - master-data FKs (AssetSubType, Location, Person, User) → TYPE_RELATION
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *
 * `name` is the unique upsert key (NotBlank on the entity). The CIA triad
 * (confidentialityValue / integrityValue / availabilityValue) and assetType are
 * flagged required (NotBlank / required:true in the form).
 *
 * The decimal money fields acquisitionValue / currentValue map to `?string`
 * setters (Doctrine DECIMAL is hydrated as a string), hence TYPE_STRING.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lockVersion,
 * lifecycle-owned `status` (mapped:false in the form — owned by
 * LifecycleService / asset_lifecycle workflow), and the M:N collections without a
 * single scalar setter (ownerDeputyPersons, dependsOn, processingActivities —
 * add/remove-only relations).
 */
final class AssetImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Asset';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Asset',
            entityClass: Asset::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'name',
                    setter: 'setName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'assetType',
                    setter: 'setAssetType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'information',
                        'software',
                        'hardware',
                        'service',
                        'personnel',
                        'physical',
                        'ai_agent',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'subType',
                    setter: 'setSubType',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: AssetSubType::class,
                    relationLookup: 'name',
                ),

                // ── Ownership ───────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'ownerUser',
                    setter: 'setOwnerUser',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: User::class,
                    relationLookup: 'email',
                ),
                new ImportFieldSpec(
                    name: 'ownerPerson',
                    setter: 'setOwnerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'owner',
                    setter: 'setOwner',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'physicalLocation',
                    setter: 'setPhysicalLocation',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Location::class,
                    relationLookup: 'name',
                ),

                // ── Valuation ───────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'acquisitionValue',
                    setter: 'setAcquisitionValue',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'currentValue',
                    setter: 'setCurrentValue',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Protection requirements (CIA triad) ─────────────────────────
                new ImportFieldSpec(
                    name: 'confidentialityValue',
                    setter: 'setConfidentialityValue',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'integrityValue',
                    setter: 'setIntegrityValue',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'availabilityValue',
                    setter: 'setAvailabilityValue',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),

                // ── Classification & handling ───────────────────────────────────
                new ImportFieldSpec(
                    name: 'dataClassification',
                    setter: 'setDataClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['public', 'internal', 'confidential', 'restricted'],
                ),
                new ImportFieldSpec(
                    name: 'acceptableUsePolicy',
                    setter: 'setAcceptableUsePolicy',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'handlingInstructions',
                    setter: 'setHandlingInstructions',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'returnDate',
                    setter: 'setReturnDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── DORA (module: nis2_dora) ────────────────────────────────────
                new ImportFieldSpec(
                    name: 'isDoraRelevant',
                    setter: 'setIsDoraRelevant',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),

                // ── TISAX overlay (module: tisax) ───────────────────────────────
                new ImportFieldSpec(
                    name: 'tisaxInformationClassification',
                    setter: 'setTisaxInformationClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'tisax',
                    enumValues: [
                        'public',
                        'internal',
                        'confidential',
                        'strictly_confidential',
                        'prototype',
                    ],
                ),

                // ── AI-Agent inventory (module: ai_governance) ──────────────────
                new ImportFieldSpec(
                    name: 'aiAgentClassification',
                    setter: 'setAiAgentClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'ai_governance',
                    enumValues: ['prohibited', 'high_risk', 'limited_risk', 'minimal_risk'],
                ),
                new ImportFieldSpec(
                    name: 'aiAgentPurpose',
                    setter: 'setAiAgentPurpose',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentDataSources',
                    setter: 'setAiAgentDataSources',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentOversightMechanism',
                    setter: 'setAiAgentOversightMechanism',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentProvider',
                    setter: 'setAiAgentProvider',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentModelVersion',
                    setter: 'setAiAgentModelVersion',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentCapabilityScope',
                    setter: 'setAiAgentCapabilityScope',
                    type: ImportFieldSpec::TYPE_LIST,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentThreatModelDocId',
                    setter: 'setAiAgentThreatModelDocId',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'ai_governance',
                ),
                new ImportFieldSpec(
                    name: 'aiAgentExtensionAllowlist',
                    setter: 'setAiAgentExtensionAllowlist',
                    type: ImportFieldSpec::TYPE_LIST,
                    module: 'ai_governance',
                ),
            ],
        );
    }
}
