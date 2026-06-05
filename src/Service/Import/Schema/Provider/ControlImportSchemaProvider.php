<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Control;
use App\Entity\Person;
use App\Entity\User;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Control} (ISO 27001:2022 Annex A controls / SoA).
 *
 * The Control entity is part of the always-on `core` set, so the whole import is
 * NOT module-gated ({@see EntityImportSchema::$module} is null). Only the
 * cloud-security reference fields are gated — they mirror the
 * `cloud_security`-conditional builder block in {@see \App\Form\ControlType} and
 * carry `module: 'cloud_security'` individually.
 *
 * Field set mirrors the user-editable fields exposed by ControlType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggle (`applicable`) → TYPE_BOOL
 *   - IntegerType → TYPE_INT (implementationPercentage), SMALLINT maturity → TYPE_INT
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (User, Person) → TYPE_RELATION resolved by a human field
 *
 * `controlId` is the natural upsert key (NotBlank + unique). Other NotBlank
 * fields (name, description, category, implementationStatus) are flagged
 * required.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lockVersion,
 * lifecycle-owned `status`, computed/derived accessors (mythos*, effectiveness
 * score, training status, framework references JSON), the variable-key
 * `frameworkReferences` JSON map, and M:N collections without a human lookup
 * (risks, protectedAssets, evidenceDocuments, responsibleDeputyPersons).
 */
final class ControlImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Control';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Control',
            entityClass: Control::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'controlId',
                    setter: 'setControlId',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'name',
                    setter: 'setName',
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
                    name: 'category',
                    setter: 'setCategory',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'organizational',
                        'people',
                        'physical',
                        'technological',
                    ],
                ),

                // ── Applicability (ISO 27001 Cl. 6.1.3 d) ───────────────────────
                new ImportFieldSpec(
                    name: 'applicable',
                    setter: 'setApplicable',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'justification',
                    setter: 'setJustification',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Implementation ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'implementationStatus',
                    setter: 'setImplementationStatus',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'not_started',
                        'planned',
                        'in_progress',
                        'implemented',
                        'verified',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'implementationPercentage',
                    setter: 'setImplementationPercentage',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'implementationNotes',
                    setter: 'setImplementationNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'targetDate',
                    setter: 'setTargetDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Classification ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'controlType',
                    setter: 'setControlType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'preventive',
                        'detective',
                        'corrective',
                        'deterrent',
                        'recovery',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'automationLevel',
                    setter: 'setAutomationLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'manual',
                        'semi_automated',
                        'fully_automated',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'controlMaturity',
                    setter: 'setControlMaturity',
                    type: ImportFieldSpec::TYPE_INT,
                ),

                // ── Effectiveness (ISO 27001 Cl. 9.1) ───────────────────────────
                new ImportFieldSpec(
                    name: 'effectiveness',
                    setter: 'setEffectiveness',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'not_assessed',
                        'ineffective',
                        'partially_effective',
                        'effective',
                        'highly_effective',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'lastEffectivenessTest',
                    setter: 'setLastEffectivenessTest',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'nextEffectivenessTest',
                    setter: 'setNextEffectivenessTest',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Responsibility (Person / User master-data FKs) ──────────────
                new ImportFieldSpec(
                    name: 'responsiblePersonUser',
                    setter: 'setResponsiblePersonUser',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: User::class,
                    relationLookup: 'email',
                ),
                new ImportFieldSpec(
                    name: 'responsiblePersonRef',
                    setter: 'setResponsiblePersonRef',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'responsiblePerson',
                    setter: 'setResponsiblePerson',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Review schedule ─────────────────────────────────────────────
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

                // ── Cloud-security references (gated 'cloud_security') ───────────
                new ImportFieldSpec(
                    name: 'cloudControlReference',
                    setter: 'setCloudControlReference',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'cloud_security',
                ),
                new ImportFieldSpec(
                    name: 'cloudPrivacyReference',
                    setter: 'setCloudPrivacyReference',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'cloud_security',
                ),
                new ImportFieldSpec(
                    name: 'pimsReference',
                    setter: 'setPimsReference',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'cloud_security',
                ),
                new ImportFieldSpec(
                    name: 'customerOrProviderResponsibility',
                    setter: 'setCustomerOrProviderResponsibility',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'cloud_security',
                    enumValues: [
                        'customer',
                        'provider',
                        'shared',
                    ],
                ),
            ],
        );
    }
}
