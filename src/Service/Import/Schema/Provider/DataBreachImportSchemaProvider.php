<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see DataBreach} (GDPR Art. 33/34 breach records).
 *
 * The whole entity is privacy-module-gated (DataBreach is listed under the
 * `privacy` module in config/modules.yaml), so the schema declares
 * `module: 'privacy'` once at the {@see EntityImportSchema} level — there are no
 * additional per-field module gates because the source {@see \App\Form\DataBreachType}
 * carries none (every field belongs to the GDPR breach record itself; no
 * DORA/NIS2 conditional gates).
 *
 * Field set mirrors the user-editable fields exposed by DataBreachType:
 *   - multi-select choice fields → TYPE_LIST (comma/semicolon-split into array)
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateTimeType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (Incident, ProcessingActivity, Person) → TYPE_RELATION
 *     resolved by a human lookup field
 *
 * `title` is the unique upsert key (NotBlank on the form). Other NotBlank
 * fields (detectedAt, dataCategories, dataSubjectCategories, breachNature,
 * likelyConsequences, measuresTaken, severity) are flagged required.
 *
 * Excluded by design: id, tenant, timestamps, lockVersion, lifecycle-owned
 * `status`, computed/derived fields, the `dataProtectionOfficer` User relation
 * (no human lookup field — only Person relations are imported), and the M:N
 * Person collections `dataProtectionOfficerDeputyPersons` /
 * `assessorDeputyPersons` (no single-value human lookup for collection import).
 */
final class DataBreachImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'DataBreach';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'DataBreach',
            entityClass: DataBreach::class,
            module: 'privacy',
            fields: [
                // ── Basic information ───────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'title',
                    setter: 'setTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'detectedAt',
                    setter: 'setDetectedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'incident',
                    setter: 'setIncident',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Incident::class,
                    relationLookup: 'incidentNumber',
                ),
                new ImportFieldSpec(
                    name: 'processingActivity',
                    setter: 'setProcessingActivity',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: ProcessingActivity::class,
                    relationLookup: 'name',
                ),

                // ── Art. 33(3) — content of notification ────────────────────────
                new ImportFieldSpec(
                    name: 'affectedDataSubjects',
                    setter: 'setAffectedDataSubjects',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'dataCategories',
                    setter: 'setDataCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                    enumValues: [
                        'personal_identification',
                        'contact_information',
                        'financial_data',
                        'health_data',
                        'location_data',
                        'online_identifiers',
                        'employment_data',
                        'education_data',
                        'criminal_convictions',
                        'biometric_data',
                        'genetic_data',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectCategories',
                    setter: 'setDataSubjectCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                    enumValues: [
                        'customers',
                        'employees',
                        'applicants',
                        'visitors',
                        'patients',
                        'students',
                        'suppliers',
                        'minors',
                        'vulnerable',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'breachNature',
                    setter: 'setBreachNature',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'likelyConsequences',
                    setter: 'setLikelyConsequences',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'measuresTaken',
                    setter: 'setMeasuresTaken',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'mitigationMeasures',
                    setter: 'setMitigationMeasures',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Risk assessment ─────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'severity',
                    setter: 'setSeverity',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),
                new ImportFieldSpec(
                    name: 'riskLevel',
                    setter: 'setRiskLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),
                new ImportFieldSpec(
                    name: 'riskAssessment',
                    setter: 'setRiskAssessment',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'specialCategoriesAffected',
                    setter: 'setSpecialCategoriesAffected',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'criminalDataAffected',
                    setter: 'setCriminalDataAffected',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),

                // ── Notification requirements (Art. 33 / Art. 34) ───────────────
                new ImportFieldSpec(
                    name: 'requiresAuthorityNotification',
                    setter: 'setRequiresAuthorityNotification',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'requiresSubjectNotification',
                    setter: 'setRequiresSubjectNotification',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'noSubjectNotificationReason',
                    setter: 'setNoSubjectNotificationReason',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Investigation & follow-up ───────────────────────────────────
                new ImportFieldSpec(
                    name: 'rootCause',
                    setter: 'setRootCause',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'lessonsLearned',
                    setter: 'setLessonsLearned',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Responsible persons (Person master-data FKs) ────────────────
                new ImportFieldSpec(
                    name: 'dataProtectionOfficerPerson',
                    setter: 'setDataProtectionOfficerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'assessorPerson',
                    setter: 'setAssessorPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
            ],
        );
    }
}
