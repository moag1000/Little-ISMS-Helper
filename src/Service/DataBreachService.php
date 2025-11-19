<?php

namespace App\Service;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Data Breaches per GDPR Art. 33/34
 *
 * CRITICAL: Handles 72-hour notification deadline tracking!
 */
class DataBreachService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DataBreachRepository $repository,
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create new data breach from incident
     * Pre-populates fields from incident for data reuse
     */
    public function createFromIncident(
        Incident $incident,
        User $createdBy,
        ?ProcessingActivity $processingActivity = null
    ): DataBreach {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            throw new \RuntimeException('No tenant context available');
        }

        // Generate reference number
        $referenceNumber = $this->repository->getNextReferenceNumber($tenant);

        $breach = new DataBreach();
        $breach->setTenant($tenant);
        $breach->setReferenceNumber($referenceNumber);
        $breach->setIncident($incident);
        $breach->setCreatedBy($createdBy);

        // Pre-populate from incident (data reuse!)
        $breach->setTitle(sprintf('Data Breach: %s', $incident->getTitle()));
        $breach->setSeverity($incident->getSeverity());

        // Link processing activity if provided
        if ($processingActivity) {
            $breach->setProcessingActivity($processingActivity);
        }

        // Set defaults per Art. 33(1) - notification required unless unlikely to result in risk
        $breach->setRequiresAuthorityNotification(true);
        $breach->setRequiresSubjectNotification(false);
        $breach->setStatus('draft');

        $this->entityManager->persist($breach);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.created',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $referenceNumber,
                'incident_id' => $incident->getId(),
                'created_by' => $createdBy->getEmail(),
            ]
        );

        $this->logger->info('Data breach created from incident', [
            'breach_id' => $breach->getId(),
            'reference_number' => $referenceNumber,
            'incident_id' => $incident->getId(),
        ]);

        return $breach;
    }

    /**
     * Create standalone data breach (without incident)
     */
    public function create(Incident $incident, User $createdBy): DataBreach
    {
        return $this->createFromIncident($incident, $createdBy);
    }

    /**
     * Update data breach
     */
    public function update(DataBreach $breach, User $updatedBy): DataBreach
    {
        $breach->setUpdatedBy($updatedBy);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.updated',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'updated_by' => $updatedBy->getEmail(),
            ]
        );

        return $breach;
    }

    /**
     * Delete data breach
     */
    public function delete(DataBreach $breach): void
    {
        $referenceNumber = $breach->getReferenceNumber();

        $this->auditLogger->log(
            'data_breach.deleted',
            DataBreach::class,
            $breach->getId(),
            null,
            ['reference_number' => $referenceNumber]
        );

        $this->entityManager->remove($breach);
        $this->entityManager->flush();

        $this->logger->info('Data breach deleted', [
            'reference_number' => $referenceNumber,
        ]);
    }

    // =========================================================================
    // WORKFLOW METHODS - Art. 33/34 GDPR
    // =========================================================================

    /**
     * Submit data breach for assessment
     * Validates completeness before submission
     */
    public function submitForAssessment(DataBreach $breach, User $assessor): DataBreach
    {
        if ($breach->getStatus() !== 'draft') {
            throw new \RuntimeException('Only draft data breaches can be submitted for assessment');
        }

        if (!$breach->isComplete()) {
            throw new \RuntimeException(sprintf(
                'Data breach must be complete before assessment (currently %d%% complete)',
                $breach->getCompletenessPercentage()
            ));
        }

        $breach->setStatus('under_assessment');
        $breach->setAssessor($assessor);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.submitted_for_assessment',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'assessor' => $assessor->getEmail(),
            ]
        );

        $this->logger->info('Data breach submitted for assessment', [
            'breach_id' => $breach->getId(),
            'reference_number' => $breach->getReferenceNumber(),
            'assessor_email' => $assessor->getEmail(),
        ]);

        return $breach;
    }

    /**
     * Notify supervisory authority (Art. 33 GDPR)
     * CRITICAL: Must be done within 72 hours of detection!
     */
    public function notifySupervisoryAuthority(
        DataBreach $breach,
        string $authorityName,
        string $notificationMethod,
        ?string $authorityReference = null,
        array $documents = []
    ): DataBreach {
        if (!$breach->isRequiresAuthorityNotification()) {
            throw new \RuntimeException('This data breach does not require supervisory authority notification');
        }

        if ($breach->getSupervisoryAuthorityNotifiedAt()) {
            throw new \RuntimeException('Supervisory authority has already been notified');
        }

        $notifiedAt = new \DateTime();
        $breach->setSupervisoryAuthorityNotifiedAt($notifiedAt);
        $breach->setSupervisoryAuthorityName($authorityName);
        $breach->setNotificationMethod($notificationMethod);
        $breach->setSupervisoryAuthorityReference($authorityReference);
        $breach->setNotificationDocuments($documents);

        // Check if notification is overdue (>72h)
        if ($breach->isAuthorityNotificationOverdue()) {
            $hoursLate = abs($breach->getHoursUntilAuthorityDeadline());
            $this->logger->warning('Supervisory authority notification is OVERDUE', [
                'breach_id' => $breach->getId(),
                'reference_number' => $breach->getReferenceNumber(),
                'hours_late' => $hoursLate,
                'detected_at' => $breach->getIncident()->getDetectedAt()->format('Y-m-d H:i:s'),
                'notified_at' => $notifiedAt->format('Y-m-d H:i:s'),
            ]);
        }

        $breach->setStatus('authority_notified');

        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.authority_notified',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'authority_name' => $authorityName,
                'notification_method' => $notificationMethod,
                'notified_at' => $notifiedAt->format('Y-m-d H:i:s'),
                'hours_until_deadline' => $breach->getHoursUntilAuthorityDeadline(),
                'overdue' => $breach->isAuthorityNotificationOverdue(),
            ]
        );

        $this->logger->info('Supervisory authority notified of data breach', [
            'breach_id' => $breach->getId(),
            'reference_number' => $breach->getReferenceNumber(),
            'authority_name' => $authorityName,
            'hours_until_deadline' => $breach->getHoursUntilAuthorityDeadline(),
        ]);

        return $breach;
    }

    /**
     * Record delayed notification reason (Art. 33(1) GDPR)
     * Required when notification exceeds 72-hour deadline
     */
    public function recordNotificationDelay(DataBreach $breach, string $delayReason): DataBreach
    {
        if (!$breach->isAuthorityNotificationOverdue()) {
            throw new \RuntimeException('Notification is not overdue - delay reason not needed');
        }

        $breach->setNotificationDelayReason($delayReason);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.notification_delay_recorded',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'delay_reason' => $delayReason,
                'hours_late' => abs($breach->getHoursUntilAuthorityDeadline()),
            ]
        );

        return $breach;
    }

    /**
     * Notify data subjects (Art. 34 GDPR)
     */
    public function notifyDataSubjects(
        DataBreach $breach,
        string $notificationMethod,
        int $subjectsNotified,
        array $documents = []
    ): DataBreach {
        if (!$breach->isRequiresSubjectNotification()) {
            throw new \RuntimeException('This data breach does not require data subject notification');
        }

        if ($breach->getDataSubjectsNotifiedAt()) {
            throw new \RuntimeException('Data subjects have already been notified');
        }

        $notifiedAt = new \DateTime();
        $breach->setDataSubjectsNotifiedAt($notifiedAt);
        $breach->setSubjectNotificationMethod($notificationMethod);
        $breach->setSubjectsNotified($subjectsNotified);
        $breach->setSubjectNotificationDocuments($documents);
        $breach->setStatus('subjects_notified');

        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.subjects_notified',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'notification_method' => $notificationMethod,
                'subjects_notified' => $subjectsNotified,
                'notified_at' => $notifiedAt->format('Y-m-d H:i:s'),
            ]
        );

        $this->logger->info('Data subjects notified of data breach', [
            'breach_id' => $breach->getId(),
            'reference_number' => $breach->getReferenceNumber(),
            'subjects_notified' => $subjectsNotified,
        ]);

        return $breach;
    }

    /**
     * Record exemption from data subject notification (Art. 34(3) GDPR)
     */
    public function recordSubjectNotificationExemption(
        DataBreach $breach,
        string $exemptionReason
    ): DataBreach {
        $breach->setRequiresSubjectNotification(false);
        $breach->setNoSubjectNotificationReason($exemptionReason);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.subject_notification_exemption',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'exemption_reason' => $exemptionReason,
            ]
        );

        return $breach;
    }

    /**
     * Close data breach investigation
     */
    public function close(DataBreach $breach, User $closedBy): DataBreach
    {
        if (!in_array($breach->getStatus(), ['authority_notified', 'subjects_notified'])) {
            throw new \RuntimeException('Data breach must be in authority_notified or subjects_notified status to close');
        }

        // Validate required notifications are complete
        if ($breach->isRequiresAuthorityNotification() && !$breach->getSupervisoryAuthorityNotifiedAt()) {
            throw new \RuntimeException('Supervisory authority notification required before closing');
        }

        if ($breach->isRequiresSubjectNotification() && !$breach->getDataSubjectsNotifiedAt()) {
            throw new \RuntimeException('Data subject notification required before closing');
        }

        $breach->setStatus('closed');
        $breach->setUpdatedBy($closedBy);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.closed',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'closed_by' => $closedBy->getEmail(),
            ]
        );

        $this->logger->info('Data breach closed', [
            'breach_id' => $breach->getId(),
            'reference_number' => $breach->getReferenceNumber(),
        ]);

        return $breach;
    }

    /**
     * Reopen closed data breach
     */
    public function reopen(DataBreach $breach, User $reopenedBy, string $reopenReason): DataBreach
    {
        if ($breach->getStatus() !== 'closed') {
            throw new \RuntimeException('Only closed data breaches can be reopened');
        }

        $breach->setStatus('under_assessment');
        $breach->setUpdatedBy($reopenedBy);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'data_breach.reopened',
            DataBreach::class,
            $breach->getId(),
            null,
            [
                'reference_number' => $breach->getReferenceNumber(),
                'reopened_by' => $reopenedBy->getEmail(),
                'reopen_reason' => $reopenReason,
            ]
        );

        return $breach;
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    public function findAll(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findByTenant($tenant);
    }

    public function findById(int $id): ?DataBreach
    {
        return $this->repository->find($id);
    }

    public function findByStatus(string $status): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findByStatus($tenant, $status);
    }

    public function findByRiskLevel(string $riskLevel): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findByRiskLevel($tenant, $riskLevel);
    }

    public function findHighRisk(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findHighRisk($tenant);
    }

    /**
     * CRITICAL: Find data breaches requiring supervisory authority notification
     */
    public function findRequiringAuthorityNotification(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findRequiringAuthorityNotification($tenant);
    }

    /**
     * CRITICAL: Find overdue notifications (>72h since detection)
     */
    public function findAuthorityNotificationOverdue(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findAuthorityNotificationOverdue($tenant);
    }

    public function findRequiringSubjectNotification(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findRequiringSubjectNotification($tenant);
    }

    public function findWithSpecialCategories(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findWithSpecialCategories($tenant);
    }

    public function findIncomplete(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findIncomplete($tenant);
    }

    public function findByProcessingActivity(ProcessingActivity $processingActivity): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findByProcessingActivity($tenant, $processingActivity->getId());
    }

    public function findRecent(int $days = 30): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        return $this->repository->findRecent($tenant, $days);
    }

    // =========================================================================
    // STATISTICS & REPORTING
    // =========================================================================

    /**
     * Get dashboard statistics
     */
    public function getDashboardStatistics(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return $this->getEmptyStatistics();
        }

        return $this->repository->getDashboardStatistics($tenant);
    }

    /**
     * Calculate GDPR Art. 33/34 compliance score
     */
    public function calculateComplianceScore(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [
                'overall_score' => 0,
                'notification_compliance' => 0,
                'timeliness_score' => 0,
                'completeness_score' => 0,
                'overdue_notifications' => 0,
                'total_affected_subjects' => 0,
            ];
        }

        $statistics = $this->repository->getDashboardStatistics($tenant);
        $allBreaches = $this->repository->findByTenant($tenant);
        $overdueCount = count($this->repository->findAuthorityNotificationOverdue($tenant));

        $total = count($allBreaches);
        if ($total === 0) {
            return [
                'overall_score' => 100,
                'notification_compliance' => 100,
                'timeliness_score' => 100,
                'completeness_score' => 100,
                'overdue_notifications' => 0,
                'total_affected_subjects' => 0,
            ];
        }

        // 1. Notification Compliance (40% weight)
        // All required notifications must be completed
        $notificationCompliance = 0;
        if ($statistics['requires_authority_notification'] > 0) {
            $notificationCompliance = ($statistics['authority_notified'] / $statistics['requires_authority_notification']) * 100;
        } else {
            $notificationCompliance = 100;
        }

        // 2. Timeliness Score (40% weight)
        // No overdue notifications (already calculated above)
        $timelinessScore = 100;
        if ($overdueCount > 0) {
            $timelinessScore = max(0, 100 - ($overdueCount / $total * 100));
        }

        // 3. Completeness Score (20% weight)
        $completenessScore = $statistics['completeness_rate'];

        // Overall score (weighted average)
        $overallScore = ($notificationCompliance * 0.4) + ($timelinessScore * 0.4) + ($completenessScore * 0.2);

        return [
            'overall_score' => (int) round($overallScore),
            'notification_compliance' => (int) round($notificationCompliance),
            'timeliness_score' => (int) round($timelinessScore),
            'completeness_score' => (int) round($completenessScore),
            'total_breaches' => $total,
            'overdue_notifications' => $overdueCount,
            'total_affected_subjects' => $this->repository->getTotalAffectedDataSubjects($tenant),
        ];
    }

    /**
     * Get action items for dashboard
     */
    public function getActionItems(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            return [];
        }

        $items = [];

        // CRITICAL: Overdue authority notifications
        $overdueBreaches = $this->repository->findAuthorityNotificationOverdue($tenant);
        foreach ($overdueBreaches as $breach) {
            $items[] = [
                'priority' => 'critical',
                'type' => 'overdue_authority_notification',
                'breach_id' => $breach->getId(),
                'reference_number' => $breach->getReferenceNumber(),
                'title' => sprintf(
                    'Supervisory authority notification OVERDUE by %d hours',
                    abs($breach->getHoursUntilAuthorityDeadline())
                ),
                'hours_overdue' => abs($breach->getHoursUntilAuthorityDeadline()),
            ];
        }

        // HIGH: Pending authority notifications (within 72h)
        $pendingAuthority = $this->repository->findRequiringAuthorityNotification($tenant);
        foreach ($pendingAuthority as $breach) {
            if (!in_array($breach->getId(), array_column($overdueBreaches, 'id'))) {
                $hoursRemaining = $breach->getHoursUntilAuthorityDeadline();
                $items[] = [
                    'priority' => $hoursRemaining < 24 ? 'high' : 'medium',
                    'type' => 'pending_authority_notification',
                    'breach_id' => $breach->getId(),
                    'reference_number' => $breach->getReferenceNumber(),
                    'title' => sprintf(
                        'Supervisory authority notification required (%d hours remaining)',
                        $hoursRemaining
                    ),
                    'hours_remaining' => $hoursRemaining,
                ];
            }
        }

        // MEDIUM: Pending subject notifications
        $pendingSubjects = $this->repository->findRequiringSubjectNotification($tenant);
        foreach ($pendingSubjects as $breach) {
            $items[] = [
                'priority' => 'medium',
                'type' => 'pending_subject_notification',
                'breach_id' => $breach->getId(),
                'reference_number' => $breach->getReferenceNumber(),
                'title' => 'Data subject notification required',
            ];
        }

        // LOW: Incomplete breaches
        $incompleteBreaches = $this->repository->findIncomplete($tenant);
        foreach ($incompleteBreaches as $breach) {
            $items[] = [
                'priority' => 'low',
                'type' => 'incomplete',
                'breach_id' => $breach->getId(),
                'reference_number' => $breach->getReferenceNumber(),
                'title' => sprintf(
                    'Incomplete data breach (%d%% complete)',
                    $breach->getCompletenessPercentage()
                ),
                'completeness' => $breach->getCompletenessPercentage(),
            ];
        }

        // Sort by priority: critical → high → medium → low
        $priorityOrder = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
        usort($items, function($a, $b) use ($priorityOrder) {
            return $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']];
        });

        return $items;
    }

    private function getEmptyStatistics(): array
    {
        return [
            'total' => 0,
            'draft' => 0,
            'under_assessment' => 0,
            'authority_notified' => 0,
            'subjects_notified' => 0,
            'closed' => 0,
            'low_risk' => 0,
            'medium_risk' => 0,
            'high_risk' => 0,
            'critical_risk' => 0,
            'requires_authority_notification' => 0,
            'authority_notified' => 0,
            'requires_subject_notification' => 0,
            'subjects_notified' => 0,
            'special_categories_affected' => 0,
            'completeness_rate' => 0,
        ];
    }
}
