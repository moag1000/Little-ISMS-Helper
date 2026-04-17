<?php

namespace App\Service;

use RuntimeException;
use DateTimeImmutable;
use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Repository\DataSubjectRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Data Subject Requests per GDPR Art. 15-22
 *
 * Handles the full lifecycle: receive, verify identity, process, complete/reject/extend.
 * Art. 12(3): 30-day deadline, extendable to 90 days for complex requests.
 */
class DataSubjectRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataSubjectRequestRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a new data subject request
     */
    public function create(DataSubjectRequest $request): DataSubjectRequest
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw new RuntimeException('No tenant context available');
        }

        $request->setTenant($tenant);

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_subject_request.created',
            DataSubjectRequest::class,
            $request->getId(),
            null,
            [
                'request_type' => $request->getRequestType(),
                'gdpr_article' => $request->getGdprArticle(),
                'data_subject_name' => $request->getDataSubjectName(),
                'deadline' => $request->getDeadlineAt()?->format('Y-m-d'),
            ]
        );

        $this->logger->info('Data subject request created', [
            'id' => $request->getId(),
            'type' => $request->getRequestType(),
            'gdpr_article' => $request->getGdprArticle(),
        ]);

        return $request;
    }

    /**
     * Update status with validation of allowed transitions
     */
    public function updateStatus(DataSubjectRequest $request, string $newStatus): void
    {
        $currentStatus = $request->getStatus();

        $allowedTransitions = [
            'received' => ['identity_verification', 'in_progress', 'rejected'],
            'identity_verification' => ['in_progress', 'rejected'],
            'in_progress' => ['completed', 'rejected', 'extended'],
            'extended' => ['completed', 'rejected', 'in_progress'],
        ];

        $allowed = $allowedTransitions[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'Cannot transition from "%s" to "%s". Allowed: %s',
                $currentStatus,
                $newStatus,
                implode(', ', $allowed)
            ));
        }

        $oldStatus = $request->getStatus();
        $request->setStatus($newStatus);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_subject_request.status_changed',
            DataSubjectRequest::class,
            $request->getId(),
            ['status' => $oldStatus],
            ['status' => $newStatus]
        );

        $this->logger->info('Data subject request status changed', [
            'id' => $request->getId(),
            'from' => $oldStatus,
            'to' => $newStatus,
        ]);
    }

    /**
     * Complete a data subject request
     */
    public function complete(DataSubjectRequest $request, string $responseDescription): void
    {
        if (in_array($request->getStatus(), ['completed', 'rejected'], true)) {
            throw new RuntimeException('Request is already in a terminal state');
        }

        $request->setStatus('completed');
        $request->setCompletedAt(new DateTimeImmutable());
        $request->setResponseDescription($responseDescription);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_subject_request.completed',
            DataSubjectRequest::class,
            $request->getId(),
            null,
            [
                'response_description' => $responseDescription,
                'completed_at' => $request->getCompletedAt()->format('Y-m-d H:i:s'),
                'days_to_complete' => $request->getReceivedAt()->diff($request->getCompletedAt())->days,
            ]
        );

        $this->logger->info('Data subject request completed', [
            'id' => $request->getId(),
            'type' => $request->getRequestType(),
        ]);
    }

    /**
     * Reject a data subject request (Art. 12(5): manifestly unfounded or excessive)
     */
    public function reject(DataSubjectRequest $request, string $reason): void
    {
        if (in_array($request->getStatus(), ['completed', 'rejected'], true)) {
            throw new RuntimeException('Request is already in a terminal state');
        }

        $request->setStatus('rejected');
        $request->setRejectionReason($reason);
        $request->setCompletedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_subject_request.rejected',
            DataSubjectRequest::class,
            $request->getId(),
            null,
            [
                'rejection_reason' => $reason,
            ]
        );

        $this->logger->info('Data subject request rejected', [
            'id' => $request->getId(),
            'reason' => $reason,
        ]);
    }

    /**
     * Extend deadline to 90 days from received date (Art. 12(3) GDPR)
     */
    public function extend(DataSubjectRequest $request, string $reason): void
    {
        if (in_array($request->getStatus(), ['completed', 'rejected'], true)) {
            throw new RuntimeException('Cannot extend a completed or rejected request');
        }

        if ($request->getExtendedDeadlineAt() !== null) {
            throw new RuntimeException('Deadline has already been extended');
        }

        $extendedDeadline = $request->getReceivedAt()->modify('+90 days');
        $request->setExtendedDeadlineAt($extendedDeadline);
        $request->setExtensionReason($reason);
        $request->setStatus('extended');

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_subject_request.extended',
            DataSubjectRequest::class,
            $request->getId(),
            ['deadline' => $request->getDeadlineAt()?->format('Y-m-d')],
            [
                'extended_deadline' => $extendedDeadline->format('Y-m-d'),
                'extension_reason' => $reason,
            ]
        );

        $this->logger->info('Data subject request deadline extended', [
            'id' => $request->getId(),
            'new_deadline' => $extendedDeadline->format('Y-m-d'),
        ]);
    }

    /**
     * Save/update a data subject request
     */
    public function update(DataSubjectRequest $request): DataSubjectRequest
    {
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_subject_request.updated',
            DataSubjectRequest::class,
            $request->getId(),
            null,
            [
                'status' => $request->getStatus(),
                'request_type' => $request->getRequestType(),
            ]
        );

        return $request;
    }

    /**
     * Delete a data subject request
     */
    public function delete(DataSubjectRequest $request): void
    {
        $id = $request->getId();

        $this->auditLogger->logCustom(
            'data_subject_request.deleted',
            DataSubjectRequest::class,
            $id,
            [
                'request_type' => $request->getRequestType(),
                'data_subject_name' => $request->getDataSubjectName(),
            ],
            null
        );

        $this->entityManager->remove($request);
        $this->entityManager->flush();

        $this->logger->info('Data subject request deleted', ['id' => $id]);
    }

    // =========================================================================
    // QUERY METHODS (tenant-filtered)
    // =========================================================================

    /**
     * Find all requests for the current tenant
     */
    public function findAll(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->repository->findByTenant($tenant);
    }

    /**
     * Find a single request by ID with tenant isolation
     */
    public function findById(int $id): ?DataSubjectRequest
    {
        $request = $this->repository->find($id);
        if ($request === null) {
            return null;
        }

        // Verify tenant isolation
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant instanceof Tenant && $request->getTenant() !== $tenant) {
            return null;
        }

        return $request;
    }

    /**
     * Find overdue requests for the current tenant
     */
    public function findOverdue(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->repository->findOverdue($tenant);
    }

    /**
     * Find requests by status for the current tenant
     */
    public function findByStatus(string $status): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->repository->findByStatus($tenant, $status);
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get comprehensive statistics for dashboard
     */
    public function getStatistics(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [
                'total' => 0,
                'by_type' => [],
                'by_status' => [],
                'overdue_count' => 0,
                'avg_response_time' => null,
            ];
        }

        $all = $this->repository->findByTenant($tenant);
        $total = count($all);

        // Count by status
        $byStatus = [];
        foreach (DataSubjectRequest::STATUSES as $status) {
            $byStatus[$status] = 0;
        }
        foreach ($all as $request) {
            $byStatus[$request->getStatus()] = ($byStatus[$request->getStatus()] ?? 0) + 1;
        }

        $byType = $this->repository->countByType($tenant);
        $overdueCount = count($this->repository->findOverdue($tenant));
        $avgResponseTime = $this->repository->getAverageResponseTime($tenant);

        return [
            'total' => $total,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'overdue_count' => $overdueCount,
            'avg_response_time' => $avgResponseTime,
        ];
    }
}
