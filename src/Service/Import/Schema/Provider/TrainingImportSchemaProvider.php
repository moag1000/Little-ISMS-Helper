<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Person;
use App\Entity\Training;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Training} (ISO 27001 §7.3 Awareness records).
 *
 * The `training` feature is part of the always-on `objectives`/core surface
 * (no optional compliance-framework gate on the source {@see \App\Form\TrainingType}
 * — every field is unconditionally added, none wrapped in an `isModuleActive`
 * block), so the schema declares no entity-level `module` and no per-field
 * module gates.
 *
 * Field set mirrors the user-editable fields exposed by TrainingType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - IntegerType fields → TYPE_INT
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FK (Person) → TYPE_RELATION resolved by a human field
 *
 * `title` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (trainingType, scheduledDate) are flagged required.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt),
 * lockVersion, lifecycle-owned `status`, derived/computed fields
 * (attendeeCount, lastReminderSentAt, programType is not in the form),
 * mapped=false file-upload field (materialFiles), the transient UI-only
 * `participantUsers` collection, M:N collections without a single human
 * lookup (coveredControls, complianceRequirements, trainerDeputyPersons),
 * and the User relation (trainerUser — no human lookup field, mirroring the
 * ProcessingActivity provider which imports only Person relations).
 */
final class TrainingImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Training';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Training',
            entityClass: Training::class,
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
                ),
                new ImportFieldSpec(
                    name: 'trainingType',
                    setter: 'setTrainingType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'security_awareness',
                        'technical',
                        'compliance',
                        'emergency_drill',
                        'phishing_simulation',
                        'data_protection',
                        'cyber_security',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'deliveryMethod',
                    setter: 'setDeliveryMethod',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'in_person',
                        'online_live',
                        'e_learning',
                        'hybrid',
                        'workshop',
                    ],
                ),

                // ── Schedule ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'scheduledDate',
                    setter: 'setScheduledDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'durationMinutes',
                    setter: 'setDurationMinutes',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'completionDate',
                    setter: 'setCompletionDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'recurrenceMonths',
                    setter: 'setRecurrenceMonths',
                    type: ImportFieldSpec::TYPE_INT,
                ),

                // ── Audience ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'targetAudience',
                    setter: 'setTargetAudience',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                // Legacy free-text participants (read-only migration display).
                new ImportFieldSpec(
                    name: 'participants',
                    setter: 'setParticipants',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Verification ────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'mandatory',
                    setter: 'setMandatory',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),

                // ── Team (Person master-data FK) ────────────────────────────────
                new ImportFieldSpec(
                    name: 'trainerPerson',
                    setter: 'setTrainerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                // Legacy free-text trainer (read-only migration display).
                new ImportFieldSpec(
                    name: 'trainer',
                    setter: 'setTrainer',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Resources ───────────────────────────────────────────────────
                // Legacy free-text materials (read-only migration display).
                new ImportFieldSpec(
                    name: 'materials',
                    setter: 'setMaterials',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'feedback',
                    setter: 'setFeedback',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
