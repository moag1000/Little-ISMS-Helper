<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see DataProtectionImpactAssessment} (GDPR Art. 35 DSFA records).
 *
 * The whole entity is privacy-module-gated (DPIA is a GDPR Art. 35 artefact),
 * so the schema declares `module: 'privacy'` once at the {@see EntityImportSchema}
 * level — there are no additional per-field module gates because the source
 * {@see \App\Form\DataProtectionImpactAssessmentType} carries none (the form is
 * only ever rendered behind the privacy module; every field is GDPR-scoped).
 *
 * Field set mirrors the user-editable fields exposed by DataProtectionImpactAssessmentType:
 *   - multi-select choice fields (JSON columns) → TYPE_LIST (comma/semicolon-split into array)
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles (ChoiceType bool) → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text → TYPE_STRING; textarea → TYPE_TEXT
 *   - master-data FKs (ProcessingActivity, Person) → TYPE_RELATION resolved by a
 *     human field that is itself a real #[ORM\Column] (ProcessingActivity.name,
 *     Person.fullName)
 *
 * `title` is the unique upsert key (per task spec). Other NotBlank entity fields
 * (referenceNumber, processingDescription, processingPurposes, dataCategories,
 * dataSubjectCategories, necessityAssessment, proportionalityAssessment,
 * legalBasis, riskLevel, technicalMeasures, organizationalMeasures) are flagged
 * required.
 *
 * Excluded by design: id, tenant, version (computed/managed), timestamps,
 * lockVersion, lifecycle-owned `status`, computed/JSON-structured fields
 * (identifiedRisks, stakeholdersConsulted, sdmAssessment + the dynamic
 * sdm_<goal> form controls which are mapped:false), M:N collections without a
 * human lookup (implementedControls, *DeputyPersons), and User relations
 * (dataProtectionOfficer, conductor, approver — no human lookup field; only the
 * Person master-data slots are imported).
 */
final class DataProtectionImpactAssessmentImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'DataProtectionImpactAssessment';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'DataProtectionImpactAssessment',
            entityClass: DataProtectionImpactAssessment::class,
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
                    name: 'referenceNumber',
                    setter: 'setReferenceNumber',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'processingActivity',
                    setter: 'setProcessingActivity',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: ProcessingActivity::class,
                    relationLookup: 'name',
                ),

                // ── Art. 35(7)(a) — Description of processing operations ─────────
                new ImportFieldSpec(
                    name: 'processingDescription',
                    setter: 'setProcessingDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'processingPurposes',
                    setter: 'setProcessingPurposes',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'dataCategories',
                    setter: 'setDataCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectCategories',
                    setter: 'setDataSubjectCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'estimatedDataSubjects',
                    setter: 'setEstimatedDataSubjects',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'dataRetentionPeriod',
                    setter: 'setDataRetentionPeriod',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'dataFlowDescription',
                    setter: 'setDataFlowDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Art. 35(7)(b) — Necessity and proportionality ───────────────
                new ImportFieldSpec(
                    name: 'necessityAssessment',
                    setter: 'setNecessityAssessment',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'proportionalityAssessment',
                    setter: 'setProportionalityAssessment',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'legalBasis',
                    setter: 'setLegalBasis',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'consent',
                        'contract',
                        'legal_obligation',
                        'vital_interests',
                        'public_task',
                        'legitimate_interests',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'legislativeCompliance',
                    setter: 'setLegislativeCompliance',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Art. 35(7)(c) — Risk assessment ─────────────────────────────
                new ImportFieldSpec(
                    name: 'riskLevel',
                    setter: 'setRiskLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),
                new ImportFieldSpec(
                    name: 'likelihood',
                    setter: 'setLikelihood',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['rare', 'unlikely', 'possible', 'likely', 'certain'],
                ),
                new ImportFieldSpec(
                    name: 'impact',
                    setter: 'setImpact',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['negligible', 'minor', 'moderate', 'major', 'severe'],
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectRisks',
                    setter: 'setDataSubjectRisks',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Art. 35(7)(d) — Measures to address risks ───────────────────
                new ImportFieldSpec(
                    name: 'technicalMeasures',
                    setter: 'setTechnicalMeasures',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'organizationalMeasures',
                    setter: 'setOrganizationalMeasures',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'complianceMeasures',
                    setter: 'setComplianceMeasures',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'residualRiskAssessment',
                    setter: 'setResidualRiskAssessment',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'residualRiskLevel',
                    setter: 'setResidualRiskLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),

                // ── Standard-Datenschutzmodell (SDM 3.1) summary ────────────────
                new ImportFieldSpec(
                    name: 'sdmAssessmentSummary',
                    setter: 'setSdmAssessmentSummary',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Stakeholder consultation (Art. 35(4), 35(9)) ────────────────
                new ImportFieldSpec(
                    name: 'dataProtectionOfficerPerson',
                    setter: 'setDataProtectionOfficerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'dpoConsultationDate',
                    setter: 'setDpoConsultationDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'dpoAdvice',
                    setter: 'setDpoAdvice',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectsConsulted',
                    setter: 'setDataSubjectsConsulted',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectConsultationDetails',
                    setter: 'setDataSubjectConsultationDetails',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Supervisory authority consultation (Art. 36) ────────────────
                new ImportFieldSpec(
                    name: 'requiresSupervisoryConsultation',
                    setter: 'setRequiresSupervisoryConsultation',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'supervisoryConsultationDate',
                    setter: 'setSupervisoryConsultationDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'supervisoryAuthorityFeedback',
                    setter: 'setSupervisoryAuthorityFeedback',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Workflow (Person master-data slots) ─────────────────────────
                new ImportFieldSpec(
                    name: 'conductorPerson',
                    setter: 'setConductorPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'approverPerson',
                    setter: 'setApproverPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),

                // ── Review (Art. 35(11)) ────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'reviewFrequencyMonths',
                    setter: 'setReviewFrequencyMonths',
                    type: ImportFieldSpec::TYPE_INT,
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
