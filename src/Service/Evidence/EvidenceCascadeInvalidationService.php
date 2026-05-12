<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Entity\Control;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\DocumentVersion;
use App\Entity\EvidenceReverificationTask;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ControlRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\EvidenceReverificationTaskRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F4 Evidence-Versioning — cross-framework cascade invalidation service.
 *
 * When a new DocumentVersion is published for a document that is linked as
 * evidence on one or more Controls or ComplianceRequirementFulfillments,
 * this service:
 *  1. Sets evidenceOutdated=true on each affected Control / CRF row.
 *  2. Creates an EvidenceReverificationTask for each affected row.
 *  3. Logs a bulk audit event (document.version.evidence_invalidated) with
 *     the total impacted count (ISO 27001 Cl.7.5.3 via AuditLogger::logBulk).
 *
 * The cascade is triggered by DocumentController after publishVersion().
 */
class EvidenceCascadeInvalidationService
{
    /**
     * Default SLA: 14 calendar days from invalidation to re-verification.
     */
    private const int DEFAULT_DUE_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceRequirementFulfillmentRepository $complianceRequirementFulfillmentRepository,
        private readonly EvidenceReverificationTaskRepository $evidenceReverificationTaskRepository,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Invalidate all controls and compliance fulfillments linked to the given
     * DocumentVersion's parent document, create reverification tasks, and
     * log the bulk audit event.
     *
     * @return array{controls: int, fulfillments: int, tasks: int}
     *   Counts of impacted rows.
     */
    public function invalidate(
        DocumentVersion $documentVersion,
        ?User $triggeredBy = null,
    ): array {
        $document = $documentVersion->getDocument();
        $tenant = $documentVersion->getTenant();

        if ($document === null || $tenant === null) {
            return ['controls' => 0, 'fulfillments' => 0, 'tasks' => 0];
        }

        $dueDate = new DateTimeImmutable(sprintf('+%d days', self::DEFAULT_DUE_DAYS));
        $perEntityData = [];
        $taskCount = 0;
        $controlCount = 0;
        $fulfillmentCount = 0;

        // --- Invalidate linked Controls ---
        $controls = $this->findControlsLinkedToDocument($document, $tenant);
        foreach ($controls as $control) {
            $control->setEvidenceOutdated(true);

            $task = $this->createTask($documentVersion, $tenant, $dueDate, $triggeredBy);
            $task->setControl($control);
            $this->entityManager->persist($task);

            $perEntityData[] = [
                'action' => 'update',
                'entity_id' => $control->getId(),
                'old_values' => ['evidence_outdated' => false],
                'new_values' => [
                    'evidence_outdated' => true,
                    'document_version_id' => $documentVersion->getId(),
                ],
            ];
            ++$controlCount;
            ++$taskCount;
        }

        // --- Invalidate linked ComplianceRequirementFulfillments ---
        $fulfillments = $this->findFulfillmentsLinkedToDocument($document, $tenant);
        foreach ($fulfillments as $fulfillment) {
            $fulfillment->setEvidenceOutdated(true);

            $task = $this->createTask($documentVersion, $tenant, $dueDate, $triggeredBy);
            $task->setComplianceFulfillment($fulfillment);
            $this->entityManager->persist($task);

            $perEntityData[] = [
                'action' => 'update',
                'entity_id' => $fulfillment->getId(),
                'old_values' => ['evidence_outdated' => false],
                'new_values' => [
                    'evidence_outdated' => true,
                    'document_version_id' => $documentVersion->getId(),
                ],
            ];
            ++$fulfillmentCount;
            ++$taskCount;
        }

        $this->entityManager->flush();

        // Bulk audit log (ISO 27001 Cl.7.5.3)
        if ($taskCount > 0) {
            $this->auditLogger->logBulk(
                eventType: 'document.version.evidence_invalidated',
                entityType: 'EvidenceReverificationTask',
                batchData: [
                    'document_id' => $document->getId(),
                    'document_version_id' => $documentVersion->getId(),
                    'version_number' => $documentVersion->getVersionNumber(),
                    'impacted_count' => $taskCount,
                    'controls_count' => $controlCount,
                    'fulfillments_count' => $fulfillmentCount,
                ],
                perEntityData: $perEntityData,
                description: sprintf(
                    'Evidence cascade invalidation: doc#%d v%d — %d impacted rows (%d controls, %d fulfillments)',
                    $document->getId() ?? 0,
                    $documentVersion->getVersionNumber(),
                    $taskCount,
                    $controlCount,
                    $fulfillmentCount,
                ),
            );
        }

        return [
            'controls' => $controlCount,
            'fulfillments' => $fulfillmentCount,
            'tasks' => $taskCount,
        ];
    }

    /**
     * Mark a control's evidence as re-verified (set evidenceOutdated=false).
     * Called after a reverification task is completed.
     */
    public function markControlReverified(Control $control, ?User $user = null): void
    {
        $control->setEvidenceOutdated(false);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'update',
            entityType: 'Control',
            entityId: $control->getId(),
            oldValues: ['evidence_outdated' => true],
            newValues: ['evidence_outdated' => false],
            description: 'Evidence re-verified for control ' . $control->getControlId(),
        );
    }

    /**
     * Mark a fulfillment's evidence as re-verified.
     */
    public function markFulfillmentReverified(ComplianceRequirementFulfillment $fulfillment, ?User $user = null): void
    {
        $fulfillment->setEvidenceOutdated(false);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'update',
            entityType: 'ComplianceRequirementFulfillment',
            entityId: $fulfillment->getId(),
            oldValues: ['evidence_outdated' => true],
            newValues: ['evidence_outdated' => false],
            description: 'Evidence re-verified for fulfillment #' . $fulfillment->getId(),
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find all Controls for the tenant that have the document in their evidenceDocuments.
     *
     * @return Control[]
     */
    private function findControlsLinkedToDocument(
        \App\Entity\Document $document,
        Tenant $tenant,
    ): array {
        return $this->controlRepository->createQueryBuilder('c')
            ->join('c.evidenceDocuments', 'd')
            ->where('c.tenant = :tenant')
            ->andWhere('d.id = :docId')
            ->setParameter('tenant', $tenant)
            ->setParameter('docId', $document->getId())
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all ComplianceRequirementFulfillments for the tenant that reference
     * the document in their evidenceDescription or have a direct document link.
     *
     * Currently, CRF does not have a direct Document FK — we fall through to an
     * empty array. When Sprint 5B adds direct evidence links on CRF, this query
     * should be extended.
     *
     * @return ComplianceRequirementFulfillment[]
     */
    private function findFulfillmentsLinkedToDocument(
        \App\Entity\Document $document,
        Tenant $tenant,
    ): array {
        // CRF does not yet have a direct document FK (Sprint 5B scope).
        // Return empty; the structure is in place for future extension.
        return [];
    }

    private function createTask(
        DocumentVersion $documentVersion,
        Tenant $tenant,
        DateTimeImmutable $dueDate,
        ?User $assignedTo,
    ): EvidenceReverificationTask {
        $task = new EvidenceReverificationTask();
        $task->setTenant($tenant);
        $task->setDocumentVersion($documentVersion);
        $task->setDueDate($dueDate);
        $task->setAssignedTo($assignedTo);
        $task->setStatus(EvidenceReverificationTask::STATUS_PENDING);
        return $task;
    }
}
