<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Enum\DataSubjectRequestStatus;
use App\Exception\Tenant\TenantOrphanException;
use App\Exception\Workflow\InvalidStatusTransitionException;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\DataSubjectRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Data Subject Requests per GDPR Art. 15-22
 *
 * Handles the full lifecycle: receive, verify identity, process, complete/reject/extend.
 * Art. 12(3): 30-day deadline, extendable to 90 days for complex requests.
 */
final class DataSubjectRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataSubjectRequestRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly LifecycleTransitionInterface $lifecycleService,
    ) {
    }

    /**
     * Create a new data subject request
     */
    public function create(DataSubjectRequest $request): DataSubjectRequest
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw new TenantOrphanException(null, 'No tenant context available');
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
     * Update status with validation of allowed transitions.
     *
     * X.6: Delegates to LifecycleService::transition() using named transitions
     * from data_subject_request_lifecycle (extended with identity_verification +
     * extended places in X.6). The transition name is resolved from the
     * (currentStatus, newStatus) pair via a canonical map.
     */
    public function updateStatus(DataSubjectRequest $request, string $newStatus, ?string $reason = null): void
    {
        $currentStatus = $request->getStatus();

        // Transition map: [fromStatus][toStatus] => transitionName
        // Mirrors the data_subject_request_lifecycle YAML transitions (X.6 extended).
        $transitionMap = [
            'received' => [
                'identity_verification' => 'verify_identity',
                'in_progress' => 'process',
                'rejected' => 'reject',
            ],
            'identity_verification' => [
                'in_progress' => 'confirm_identity',
                'rejected' => 'reject',
            ],
            'in_progress' => [
                'completed' => 'complete',
                'rejected' => 'reject',
                'extended' => 'extend_deadline',
            ],
            'extended' => [
                'completed' => 'complete',
                'rejected' => 'reject',
                'in_progress' => 'resume_processing',
            ],
        ];

        $transitionName = $transitionMap[$currentStatus][$newStatus] ?? null;
        if ($transitionName === null) {
            $allowed = array_keys($transitionMap[$currentStatus] ?? []);
            throw new \App\Exception\BusinessRule\BusinessRuleException(sprintf(
                'Cannot transition from "%s" to "%s". Allowed targets: %s',
                $currentStatus,
                $newStatus,
                $allowed === [] ? '<none>' : implode(', ', $allowed),
            ), 'invalid_transition');
        }

        $oldStatus = $currentStatus;
        // X.6: LifecycleService::transition() handles setStatus + flush + audit-log hook.
        $this->lifecycleService->transition(
            $request,
            'data_subject_request_lifecycle',
            $transitionName,
            null, // user not available at this call-site; callers with User context should pass it
            $reason,
        );

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
        if (in_array($request->getStatus(), [DataSubjectRequestStatus::Completed->value, DataSubjectRequestStatus::Rejected->value], true)) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Request is already in a terminal state', 'terminal_state');
        }

        $request->setCompletedAt(new DateTimeImmutable());
        $request->setResponseDescription($responseDescription);

        $this->entityManager->flush();
        $this->lifecycleService->transition($request, 'data_subject_request_lifecycle', 'complete');

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
        if (in_array($request->getStatus(), [DataSubjectRequestStatus::Completed->value, DataSubjectRequestStatus::Rejected->value], true)) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Request is already in a terminal state', 'terminal_state');
        }

        $request->setRejectionReason($reason);
        $request->setCompletedAt(new DateTimeImmutable());

        $this->entityManager->flush();
        $this->lifecycleService->transition($request, 'data_subject_request_lifecycle', 'reject', null, $reason);

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
        if (in_array($request->getStatus(), [DataSubjectRequestStatus::Completed->value, DataSubjectRequestStatus::Rejected->value], true)) {
            throw new InvalidStatusTransitionException(
                (string) $request->getStatus(),
                'extended',
                DataSubjectRequest::class,
                'Cannot extend a completed or rejected request',
            );
        }

        if ($request->getExtendedDeadlineAt() !== null) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Deadline has already been extended', 'already_extended');
        }

        $extendedDeadline = $request->getReceivedAt()->modify('+90 days');
        $request->setExtendedDeadlineAt($extendedDeadline);
        $request->setExtensionReason($reason);
        // X.6: extend_deadline transition — data_subject_request_lifecycle extended with
        // 'extended' place in X.6 (config/workflows/data_subject_request.yaml).
        // Works from received / identity_verification / in_progress.
        $this->lifecycleService->transition(
            $request,
            'data_subject_request_lifecycle',
            'extend_deadline',
            null,
            $reason,
        );
        // Note: LifecycleService::transition() flushes internally.
        // setExtendedDeadlineAt() + setExtensionReason() mutations above are included.

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
