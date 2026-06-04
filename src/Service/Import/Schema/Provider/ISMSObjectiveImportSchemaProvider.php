<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\ISMSObjective;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see ISMSObjective} (ISO 27001 Cl. 6.2 security objectives).
 *
 * The entity is not module-gated (objectives is one of the six required modules),
 * so the schema declares `module: null` at the {@see EntityImportSchema} level and
 * the source {@see \App\Form\ISMSObjectiveType} carries no per-field module gates
 * either (no DORA/NIS2/privacy conditional blocks).
 *
 * Field set mirrors the user-editable `->add()` calls of ISMSObjectiveType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - NumberType decimals → TYPE_FLOAT (the `?string` decimal setters accept the cast value)
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *
 * `title` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (description, category, responsiblePerson, targetDate) are flagged required.
 *
 * `responsiblePerson` is a plain string column on the entity (not a Person FK),
 * so it is imported as TYPE_STRING rather than a relation lookup.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lockVersion,
 * lifecycle-owned `status` (workflow `isms_objective_lifecycle`, mapped=false in
 * the form), the lifecycle-side-effect `achievedDate`, and the computed
 * `progressPercentage`.
 */
final class ISMSObjectiveImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'ISMSObjective';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'ISMSObjective',
            entityClass: ISMSObjective::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
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
                    name: 'category',
                    setter: 'setCategory',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'availability',
                        'confidentiality',
                        'integrity',
                        'compliance',
                        'risk_management',
                        'incident_response',
                        'awareness',
                        'continual_improvement',
                    ],
                ),

                // ── Target metric ───────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'measurableIndicators',
                    setter: 'setMeasurableIndicators',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'targetValue',
                    setter: 'setTargetValue',
                    type: ImportFieldSpec::TYPE_FLOAT,
                ),
                new ImportFieldSpec(
                    name: 'currentValue',
                    setter: 'setCurrentValue',
                    type: ImportFieldSpec::TYPE_FLOAT,
                ),
                new ImportFieldSpec(
                    name: 'unit',
                    setter: 'setUnit',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        '%',
                        'days',
                        'hours',
                        'count',
                        'incidents',
                        'employees',
                        '€',
                        'points',
                    ],
                ),

                // ── Monitoring ──────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'measurementFrequency',
                    setter: 'setMeasurementFrequency',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'daily',
                        'weekly',
                        'monthly',
                        'quarterly',
                        'biannually',
                        'annually',
                        'on_event',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'measurementMethod',
                    setter: 'setMeasurementMethod',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Responsibility ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'responsiblePerson',
                    setter: 'setResponsiblePerson',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'responsibleForMeasurement',
                    setter: 'setResponsibleForMeasurement',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'targetDate',
                    setter: 'setTargetDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),

                // ── Notes ───────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'progressNotes',
                    setter: 'setProgressNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
