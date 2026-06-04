<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Incident;
use App\Entity\Person;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Incident} (ISO 27001 A.5.24–A.5.28 incident records).
 *
 * Incident is a CORE entity (no entity-level module gate). A subset of fields is
 * gated on the `nis2_dora` module — the same gate the source {@see \App\Form\IncidentType}
 * uses to wrap NIS2 Art. 23 + DORA Art. 17-19 reporting fields behind
 * `if ($this->isModuleActive('nis2_dora'))`. Those fields carry `module: 'nis2_dora'`
 * so a tenant without the EU-cyber-reporting obligation never sees those columns.
 *
 * Field set mirrors the user-editable fields exposed by IncidentType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - EnumType (IncidentSeverity) → TYPE_ENUM (low/medium/high/critical)
 *   - yes/no toggles + CheckboxType → TYPE_BOOL
 *   - DateTimeType single_text fields → TYPE_DATE
 *   - IntegerType → TYPE_INT
 *   - MoneyType / NumberType (entity setters take string) → TYPE_STRING
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (Person) → TYPE_RELATION resolved by `fullName`
 *
 * `title` is the unique upsert key (NotBlank on the form). `description`,
 * `category`, `severity`, `dataBreachOccurred` and `detectedAt` are the form's
 * other `required: true` fields and are flagged required.
 *
 * Excluded by design:
 *   - id, tenant, timestamps (createdAt/updatedAt), lockVersion
 *   - lifecycle-owned `status` (IncidentType renders it disabled + mapped=false —
 *     transitions are owned exclusively by LifecycleService)
 *   - deprecated/disabled `affectedSystems` (IncidentType disables it; superseded
 *     by structured affectedAssets)
 *   - M:N collections without a single-value human lookup
 *     (reportedByDeputyPersons, affectedAssets, criticalServicesAffected)
 *   - User relation reportedByUser (no human lookup field is imported — only
 *     Person relations are, mirroring the ProcessingActivity provider convention;
 *     the legacy free-text `reportedBy` slot carries the human reporter instead)
 *   - fields not present in IncidentType (incidentNumber, assignedTo,
 *     immediateActions, preventiveActions, notificationRequired, resolvedAt,
 *     originatingThreat, evidenceArtifactsJson, dora* duplicate setters)
 */
final class IncidentImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Incident';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Incident',
            entityClass: Incident::class,
            module: null,
            fields: [
                // ── Core identification ─────────────────────────────────────────
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
                        'data_breach',
                        'security_incident',
                        'system_outage',
                        'compliance_violation',
                        'physical_security',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'severity',
                    setter: 'setSeverity',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),
                new ImportFieldSpec(
                    name: 'dataBreachOccurred',
                    setter: 'setDataBreachOccurred',
                    type: ImportFieldSpec::TYPE_BOOL,
                    required: true,
                ),

                // ── Timeline ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'detectedAt',
                    setter: 'setDetectedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'occurredAt',
                    setter: 'setOccurredAt',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'closedAt',
                    setter: 'setClosedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Reporter / responsible (OwnerPicker cluster) ────────────────
                new ImportFieldSpec(
                    name: 'reportedBy',
                    setter: 'setReportedBy',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'reportedByPerson',
                    setter: 'setReportedByPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'responsiblePerson',
                    setter: 'setResponsiblePerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),

                // ── Investigation & resolution ──────────────────────────────────
                new ImportFieldSpec(
                    name: 'rootCause',
                    setter: 'setRootCause',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'correctiveActions',
                    setter: 'setCorrectiveActions',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'lessonsLearned',
                    setter: 'setLessonsLearned',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Holding visibility ──────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'visibleToHolding',
                    setter: 'setVisibleToHolding',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),

                // ── ISO 27001 A.5.24–A.5.28 ─────────────────────────────────────
                new ImportFieldSpec(
                    name: 'incidentClassification',
                    setter: 'setIncidentClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['event', 'incident'],
                ),
                new ImportFieldSpec(
                    name: 'containmentActions',
                    setter: 'setContainmentActions',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'evidencePreserved',
                    setter: 'setEvidencePreserved',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),

                // ── NIS2 Art. 23 reporting timeline (nis2_dora-gated) ───────────
                new ImportFieldSpec(
                    name: 'nis2Category',
                    setter: 'setNis2Category',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'nis2_dora',
                    enumValues: ['operational', 'security', 'privacy', 'availability'],
                ),
                new ImportFieldSpec(
                    name: 'earlyWarningReportedAt',
                    setter: 'setEarlyWarningReportedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'detailedNotificationReportedAt',
                    setter: 'setDetailedNotificationReportedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'finalReportSubmittedAt',
                    setter: 'setFinalReportSubmittedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'crossBorderImpact',
                    setter: 'setCrossBorderImpact',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'affectedUsersCount',
                    setter: 'setAffectedUsersCount',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'estimatedFinancialImpact',
                    setter: 'setEstimatedFinancialImpact',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'nationalAuthorityNotified',
                    setter: 'setNationalAuthorityNotified',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'authorityReferenceNumber',
                    setter: 'setAuthorityReferenceNumber',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),

                // ── DORA Art. 17-19 ICT-incident reporting (nis2_dora-gated) ────
                new ImportFieldSpec(
                    name: 'ictIncidentClassification',
                    setter: 'setIctIncidentClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'nis2_dora',
                    enumValues: ['major_ict_incident', 'significant_cyber_threat'],
                ),
                new ImportFieldSpec(
                    name: 'dataLossOccurred',
                    setter: 'setDataLossOccurred',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'dataLeakageOccurred',
                    setter: 'setDataLeakageOccurred',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'economicImpact',
                    setter: 'setEconomicImpact',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'reputationalImpact',
                    setter: 'setReputationalImpact',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'recurringIncident',
                    setter: 'setRecurringIncident',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'clientsAffected',
                    setter: 'setClientsAffected',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'clientsAffectedFinancialVolume',
                    setter: 'setClientsAffectedFinancialVolume',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'replicationOfImpact',
                    setter: 'setReplicationOfImpact',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'initialReportSubmittedAt',
                    setter: 'setInitialReportSubmittedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'intermediateReportSubmittedAt',
                    setter: 'setIntermediateReportSubmittedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'dataRecoveryStrategy',
                    setter: 'setDataRecoveryStrategy',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'nis2_dora',
                ),
            ],
        );
    }
}
