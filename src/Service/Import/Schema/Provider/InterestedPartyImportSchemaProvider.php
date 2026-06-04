<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\InterestedParty;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see InterestedParty} (ISO 27001 Cl. 4.2 — needs and
 * expectations of interested parties).
 *
 * This is a core ISMS context entity, so the schema declares no module gate at
 * the {@see EntityImportSchema} level (`module: null`) and no per-field gates —
 * the source {@see \App\Form\InterestedPartyType} carries no DORA/NIS2/privacy
 * conditional blocks.
 *
 * Field set mirrors the user-editable fields exposed by InterestedPartyType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - DateType single_text fields → TYPE_DATE
 *   - IntegerType → TYPE_INT
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *
 * `name` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (partyType, importance, requirements) are flagged required.
 *
 * Enum value lists use the entity's authoritative `Assert\Choice` constraints,
 * not the (broader) form choice lists — `partyType` form choices add
 * `works_council`/`supervisory_board`/`union`, which the entity does not accept,
 * so the import validates against the 11 entity-permitted values only.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lockVersion,
 * lifecycle-owned `status`, JSON-structured `legalRequirements` (not in the
 * form), and computed accessors (communicationStatus, engagementScore).
 */
final class InterestedPartyImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'InterestedParty';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'InterestedParty',
            entityClass: InterestedParty::class,
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
                    name: 'partyType',
                    setter: 'setPartyType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'customer',
                        'shareholder',
                        'employee',
                        'regulator',
                        'supplier',
                        'partner',
                        'public',
                        'media',
                        'government',
                        'competitor',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'importance',
                    setter: 'setImportance',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['critical', 'high', 'medium', 'low'],
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Contact ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'contactPerson',
                    setter: 'setContactPerson',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'email',
                    setter: 'setEmail',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'phone',
                    setter: 'setPhone',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Requirements ────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'requirements',
                    setter: 'setRequirements',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'howAddressed',
                    setter: 'setHowAddressed',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Communication ───────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'communicationFrequency',
                    setter: 'setCommunicationFrequency',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'daily',
                        'weekly',
                        'monthly',
                        'quarterly',
                        'annually',
                        'as_needed',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'communicationMethod',
                    setter: 'setCommunicationMethod',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'lastCommunication',
                    setter: 'setLastCommunication',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'nextCommunication',
                    setter: 'setNextCommunication',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Monitoring ──────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'feedback',
                    setter: 'setFeedback',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'satisfactionLevel',
                    setter: 'setSatisfactionLevel',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'issues',
                    setter: 'setIssues',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
