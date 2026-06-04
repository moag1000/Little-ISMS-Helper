<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Document;
use App\Entity\ManagementReview;
use App\Entity\Person;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see ManagementReview} (ISO 27001 Cl. 9.3 records).
 *
 * The entity itself is not module-gated, so the {@see EntityImportSchema}
 * declares `module: null`. Field set mirrors the user-editable fields exposed
 * by {@see \App\Form\ManagementReviewType}:
 *   - free-text / textarea (ISO 27001 §9.3 review inputs/outputs) → TYPE_TEXT
 *   - DateType single_text fields → TYPE_DATE
 *   - yes/no toggle (topManagementAttended) → TYPE_BOOL
 *   - master-data FKs (Person, Document) → TYPE_RELATION resolved by a human field
 *
 * `title` is the unique upsert key (NotBlank on the form). `reviewDate` is the
 * other NotBlank field and is flagged required.
 *
 * Excluded by design:
 *   - id, tenant, createdAt/updatedAt timestamps, lockVersion (lifecycle/ORM
 *     infrastructure)
 *   - `status` — owned by `management_review_lifecycle` (mapped=false in the
 *     form, transitions only via LifecycleService)
 *   - M:N collections without a single human lookup setter: participants (User),
 *     personParticipants (Person), reviewedByDeputyPersons (Person)
 *   - `reviewedBy` (User relation — no human lookup field is imported, mirroring
 *     ProcessingActivity which imports only Person relations)
 *   - JSON-structured fields: actionItemsWithDeadlines, frameworkComplianceStatus
 *   - computed/derived accessors (daysSinceReview, effectiveParticipant*,
 *     effectiveReviewedBy, allReviewedByOwners, resourcesNeeded has no form field)
 *
 * relationLookup targets are real #[ORM\Column]s: Person::$fullName and
 * Document::$originalFilename.
 */
final class ManagementReviewImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'ManagementReview';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'ManagementReview',
            entityClass: ManagementReview::class,
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
                    name: 'reviewDate',
                    setter: 'setReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'reviewedByPerson',
                    setter: 'setReviewedByPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),

                // ── Review inputs (ISO 27001 §9.3.2) ────────────────────────────
                new ImportFieldSpec(
                    name: 'performanceEvaluation',
                    setter: 'setPerformanceEvaluation',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'changesRelevantToISMS',
                    setter: 'setChangesRelevantToISMS',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'feedbackFromInterestedParties',
                    setter: 'setFeedbackFromInterestedParties',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'auditResults',
                    setter: 'setAuditResults',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'nonconformitiesReview',
                    setter: 'setNonconformitiesReview',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'incidentsReview',
                    setter: 'setIncidentsReview',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'risksReview',
                    setter: 'setRisksReview',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'objectivesReview',
                    setter: 'setObjectivesReview',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'contextChanges',
                    setter: 'setContextChanges',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'previousReviewActions',
                    setter: 'setPreviousReviewActions',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Follow-up ───────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'nonConformitiesStatus',
                    setter: 'setNonConformitiesStatus',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'correctiveActionsStatus',
                    setter: 'setCorrectiveActionsStatus',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'improvementOpportunities',
                    setter: 'setImprovementOpportunities',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Review outputs (ISO 27001 §9.3.3) ───────────────────────────
                new ImportFieldSpec(
                    name: 'opportunitiesForImprovement',
                    setter: 'setOpportunitiesForImprovement',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'decisions',
                    setter: 'setDecisions',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'actionItems',
                    setter: 'setActionItems',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'resourceNeeds',
                    setter: 'setResourceNeeds',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'summary',
                    setter: 'setSummary',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Audit metadata (ISO 27001 §9.3 norm fields) ─────────────────
                new ImportFieldSpec(
                    name: 'topManagementAttended',
                    setter: 'setTopManagementAttended',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'nextReviewDate',
                    setter: 'setNextReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'meetingMinutesDocument',
                    setter: 'setMeetingMinutesDocument',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Document::class,
                    relationLookup: 'originalFilename',
                ),
                new ImportFieldSpec(
                    name: 'riskTreatmentEffectiveness',
                    setter: 'setRiskTreatmentEffectiveness',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'policyReviewOutcome',
                    setter: 'setPolicyReviewOutcome',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
