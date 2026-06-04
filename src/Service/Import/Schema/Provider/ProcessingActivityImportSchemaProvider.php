<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Department;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see ProcessingActivity} (GDPR Art. 30 VVT records).
 *
 * The whole entity is privacy-module-gated, so the schema declares
 * `module: 'privacy'` once at the {@see EntityImportSchema} level — there are no
 * additional per-field module gates because the source {@see \App\Form\ProcessingActivityType}
 * carries none (no DORA/NIS2 fields; every conditional builder block is gated on
 * `privacy`, the same module that already gates the whole import).
 *
 * Field set mirrors the user-editable fields exposed by ProcessingActivityType:
 *   - multi-select choice fields → TYPE_LIST (comma/semicolon-split into array)
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (Department, Person) → TYPE_RELATION resolved by a human field
 *
 * `name` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (purposes, dataSubjectCategories, personalDataCategories,
 * retentionPeriod, legalBasis) are flagged required.
 *
 * Excluded by design: id, tenant, timestamps, lockVersion, lifecycle-owned
 * `status`, computed fields, M:N collections without a human lookup
 * (implementedControls, assets, processorSuppliers), JSON-structured fields
 * (retentionPerCategory, jointControllerDetails), and User relations
 * (no human lookup field — only Person relations are imported).
 */
final class ProcessingActivityImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'ProcessingActivity';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'ProcessingActivity',
            entityClass: ProcessingActivity::class,
            module: 'privacy',
            fields: [
                // ── Basic information (Art. 30(1)(a)) ───────────────────────────
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
                    name: 'purposes',
                    setter: 'setPurposes',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                ),

                // ── Data subjects (Art. 30(1)(b)) ───────────────────────────────
                new ImportFieldSpec(
                    name: 'dataSubjectCategories',
                    setter: 'setDataSubjectCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'estimatedDataSubjectsCount',
                    setter: 'setEstimatedDataSubjectsCount',
                    type: ImportFieldSpec::TYPE_INT,
                ),

                // ── Personal data categories (Art. 30(1)(c)) ────────────────────
                new ImportFieldSpec(
                    name: 'personalDataCategories',
                    setter: 'setPersonalDataCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'processesSpecialCategories',
                    setter: 'setProcessesSpecialCategories',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'specialCategoriesDetails',
                    setter: 'setSpecialCategoriesDetails',
                    type: ImportFieldSpec::TYPE_LIST,
                ),
                new ImportFieldSpec(
                    name: 'processesCriminalData',
                    setter: 'setProcessesCriminalData',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'criminalDataLegalBasis',
                    setter: 'setCriminalDataLegalBasis',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Recipients (Art. 30(1)(d)) ──────────────────────────────────
                new ImportFieldSpec(
                    name: 'recipientCategories',
                    setter: 'setRecipientCategories',
                    type: ImportFieldSpec::TYPE_LIST,
                ),
                new ImportFieldSpec(
                    name: 'recipientDetails',
                    setter: 'setRecipientDetails',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Third-country transfers (Art. 30(1)(e)) ─────────────────────
                new ImportFieldSpec(
                    name: 'hasThirdCountryTransfer',
                    setter: 'setHasThirdCountryTransfer',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'thirdCountries',
                    setter: 'setThirdCountries',
                    type: ImportFieldSpec::TYPE_LIST,
                ),
                new ImportFieldSpec(
                    name: 'transferSafeguards',
                    setter: 'setTransferSafeguards',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'adequacy_decision',
                        'standard_contractual_clauses',
                        'binding_corporate_rules',
                        'certification',
                        'codes_of_conduct',
                        'explicit_consent',
                        'contract_necessity',
                        'public_interest',
                        'legal_claims',
                        'vital_interests',
                    ],
                ),

                // ── Retention periods (Art. 30(1)(f)) ───────────────────────────
                new ImportFieldSpec(
                    name: 'retentionPeriod',
                    setter: 'setRetentionPeriod',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'retentionPeriodDays',
                    setter: 'setRetentionPeriodDays',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'retentionLegalBasis',
                    setter: 'setRetentionLegalBasis',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Technical & organizational measures (Art. 30(1)(g)) ─────────
                new ImportFieldSpec(
                    name: 'technicalOrganizationalMeasures',
                    setter: 'setTechnicalOrganizationalMeasures',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Legal basis (Art. 6 / Art. 9) ───────────────────────────────
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
                    name: 'legalBasisDetails',
                    setter: 'setLegalBasisDetails',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'legalBasisSpecialCategories',
                    setter: 'setLegalBasisSpecialCategories',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'explicit_consent',
                        'employment_law',
                        'vital_interests',
                        'legitimate_activities',
                        'made_public',
                        'legal_claims',
                        'substantial_public_interest',
                        'health_care',
                        'public_health',
                        'research_statistics',
                    ],
                ),

                // ── Organizational details ──────────────────────────────────────
                new ImportFieldSpec(
                    name: 'responsibleDepartmentEntity',
                    setter: 'setResponsibleDepartmentEntity',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Department::class,
                    relationLookup: 'name',
                ),
                new ImportFieldSpec(
                    name: 'responsibleDepartment',
                    setter: 'setResponsibleDepartment',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Processors / joint controllers (Art. 28 / Art. 26) ──────────
                new ImportFieldSpec(
                    name: 'involvesProcessors',
                    setter: 'setInvolvesProcessors',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'isJointController',
                    setter: 'setIsJointController',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'isProcessor',
                    setter: 'setIsProcessor',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'processorClientController',
                    setter: 'setProcessorClientController',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Risk & DPIA (Art. 35) ───────────────────────────────────────
                new ImportFieldSpec(
                    name: 'isHighRisk',
                    setter: 'setIsHighRisk',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'dpiaCompleted',
                    setter: 'setDpiaCompleted',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'dpiaDate',
                    setter: 'setDpiaDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'riskLevel',
                    setter: 'setRiskLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),

                // ── Automated decision-making (Art. 22) ─────────────────────────
                new ImportFieldSpec(
                    name: 'hasAutomatedDecisionMaking',
                    setter: 'setHasAutomatedDecisionMaking',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'automatedDecisionMakingDetails',
                    setter: 'setAutomatedDecisionMakingDetails',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Data sources ────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'dataSources',
                    setter: 'setDataSources',
                    type: ImportFieldSpec::TYPE_LIST,
                ),

                // ── Schedule ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'startDate',
                    setter: 'setStartDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'endDate',
                    setter: 'setEndDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'nextReviewDate',
                    setter: 'setNextReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Contacts / DPO (Person master-data FKs) ─────────────────────
                new ImportFieldSpec(
                    name: 'contactPerson',
                    setter: 'setContactPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'dataProtectionOfficerPerson',
                    setter: 'setDataProtectionOfficerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
            ],
        );
    }
}
