<?php

namespace App\Service;

use RuntimeException;
use DateTimeInterface;
use Deprecated;
use DateTime;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Prepare a new DataBreach entity with tenant and reference number already set
     * Used for form binding (validation requires these fields)
     */
    public function prepareNewBreach(): DataBreach
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw new RuntimeException('No tenant context available');
        }

        $referenceNumber = $this->dataBreachRepository->getNextReferenceNumber($tenant);

        $dataBreach = new DataBreach();
        $dataBreach->setTenant($tenant);
        $dataBreach->setReferenceNumber($referenceNumber);
        $dataBreach->setStatus('draft');
        // Defaults to false - user decides based on risk assessment
        $dataBreach->setRequiresAuthorityNotification(false);
        $dataBreach->setRequiresSubjectNotification(false);

        return $dataBreach;
    }

    /**
     * Create new data breach from incident
     * Pre-populates fields from incident for data reuse
     */
    public function createFromIncident(
        Incident $incident,
        User $user,
        ?ProcessingActivity $processingActivity = null
    ): DataBreach {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw new RuntimeException('No tenant context available');
        }

        // Generate reference number
        $referenceNumber = $this->dataBreachRepository->getNextReferenceNumber($tenant);

        $dataBreach = new DataBreach();
        $dataBreach->setTenant($tenant);
        $dataBreach->setReferenceNumber($referenceNumber);
        $dataBreach->setIncident($incident);
        $dataBreach->setCreatedBy($user);

        // Pre-populate from incident (data reuse!)
        $dataBreach->setTitle(sprintf('Data Breach: %s', $incident->getTitle()));
        $dataBreach->setSeverity($incident->getSeverity());

        // Link processing activity if provided
        if ($processingActivity instanceof ProcessingActivity) {
            $dataBreach->setProcessingActivity($processingActivity);
        }

        // Set defaults per Art. 33(1) - notification required unless unlikely to result in risk
        $dataBreach->setRequiresAuthorityNotification(true);
        $dataBreach->setRequiresSubjectNotification(false);
        $dataBreach->setStatus('draft');

        $this->entityManager->persist($dataBreach);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.created',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $referenceNumber,
                'incident_id' => $incident->getId(),
                'created_by' => $user->getEmail(),
            ]
        );

        $this->logger->info('Data breach created from incident', [
            'breach_id' => $dataBreach->getId(),
            'reference_number' => $referenceNumber,
            'incident_id' => $incident->getId(),
        ]);

        return $dataBreach;
    }

    /**
     * Create standalone data breach (without linking to a security incident)
     * Used when the breach is not caused by/related to a security incident
     * (e.g., accidental email to wrong recipient, paper documents lost)
     */
    public function createStandalone(
        User $user,
        DateTimeInterface $detectedAt,
        ?ProcessingActivity $processingActivity = null
    ): DataBreach {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw new RuntimeException('No tenant context available');
        }

        // Generate reference number
        $referenceNumber = $this->dataBreachRepository->getNextReferenceNumber($tenant);

        $dataBreach = new DataBreach();
        $dataBreach->setTenant($tenant);
        $dataBreach->setReferenceNumber($referenceNumber);
        $dataBreach->setDetectedAt($detectedAt);
        $dataBreach->setCreatedBy($user);

        // Link processing activity if provided
        if ($processingActivity instanceof ProcessingActivity) {
            $dataBreach->setProcessingActivity($processingActivity);
        }

        // Set defaults per Art. 33(1)
        $dataBreach->setRequiresAuthorityNotification(true);
        $dataBreach->setRequiresSubjectNotification(false);
        $dataBreach->setStatus('draft');

        return $dataBreach;
    }

    #[Deprecated(message: 'Use createStandalone() or createFromIncident() instead')]
    public function create(Incident $incident, User $user): DataBreach
    {
        return $this->createFromIncident($incident, $user);
    }

    /**
     * Save (create or update) data breach
     */
    public function update(DataBreach $dataBreach, User $user): DataBreach
    {
        $isNew = $dataBreach->getId() === null;
        $dataBreach->setUpdatedBy($user);

        // Persist if new entity
        if ($isNew) {
            $this->entityManager->persist($dataBreach);
        }

        $this->entityManager->flush();

        $action = $isNew ? 'data_breach.created' : 'data_breach.updated';
        $this->auditLogger->logCustom(
            $action,
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'updated_by' => $user->getEmail(),
            ]
        );

        if ($isNew) {
            $this->logger->info('Data breach created (standalone)', [
                'breach_id' => $dataBreach->getId(),
                'reference_number' => $dataBreach->getReferenceNumber(),
            ]);
        }

        return $dataBreach;
    }

    /**
     * Delete data breach
     */
    public function delete(DataBreach $dataBreach): void
    {
        $referenceNumber = $dataBreach->getReferenceNumber();

        $this->auditLogger->logCustom(
            'data_breach.deleted',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            ['reference_number' => $referenceNumber]
        );

        $this->entityManager->remove($dataBreach);
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
    public function submitForAssessment(DataBreach $dataBreach, User $user): DataBreach
    {
        if ($dataBreach->getStatus() !== 'draft') {
            throw new RuntimeException('Only draft data breaches can be submitted for assessment');
        }

        if (!$dataBreach->isComplete()) {
            throw new RuntimeException(sprintf(
                'Data breach must be complete before assessment (currently %d%% complete)',
                $dataBreach->getCompletenessPercentage()
            ));
        }

        $dataBreach->setStatus('under_assessment');
        $dataBreach->setAssessor($user);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.submitted_for_assessment',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'assessor' => $user->getEmail(),
            ]
        );

        $this->logger->info('Data breach submitted for assessment', [
            'breach_id' => $dataBreach->getId(),
            'reference_number' => $dataBreach->getReferenceNumber(),
            'assessor_email' => $user->getEmail(),
        ]);

        return $dataBreach;
    }

    /**
     * Notify supervisory authority (Art. 33 GDPR)
     * CRITICAL: Must be done within 72 hours of detection!
     */
    public function notifySupervisoryAuthority(
        DataBreach $dataBreach,
        string $authorityName,
        string $notificationMethod,
        ?string $authorityReference = null,
        array $documents = []
    ): DataBreach {
        if (!$dataBreach->getRequiresAuthorityNotification()) {
            throw new RuntimeException('This data breach does not require supervisory authority notification');
        }

        if ($dataBreach->getSupervisoryAuthorityNotifiedAt() instanceof DateTimeInterface) {
            throw new RuntimeException('Supervisory authority has already been notified');
        }

        $notifiedAt = new DateTime();
        $dataBreach->setSupervisoryAuthorityNotifiedAt($notifiedAt);
        $dataBreach->setSupervisoryAuthorityName($authorityName);
        $dataBreach->setNotificationMethod($notificationMethod);
        $dataBreach->setSupervisoryAuthorityReference($authorityReference);
        $dataBreach->setNotificationDocuments($documents);

        // Check if notification is overdue (>72h)
        if ($dataBreach->isAuthorityNotificationOverdue()) {
            $hoursLate = abs($dataBreach->getHoursUntilAuthorityDeadline());
            $this->logger->warning('Supervisory authority notification is OVERDUE', [
                'breach_id' => $dataBreach->getId(),
                'reference_number' => $dataBreach->getReferenceNumber(),
                'hours_late' => $hoursLate,
                'detected_at' => $dataBreach->getIncident()->getDetectedAt()->format('Y-m-d H:i:s'),
                'notified_at' => $notifiedAt->format('Y-m-d H:i:s'),
            ]);
        }

        $dataBreach->setStatus('authority_notified');

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.authority_notified',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'authority_name' => $authorityName,
                'notification_method' => $notificationMethod,
                'notified_at' => $notifiedAt->format('Y-m-d H:i:s'),
                'hours_until_deadline' => $dataBreach->getHoursUntilAuthorityDeadline(),
                'overdue' => $dataBreach->isAuthorityNotificationOverdue(),
            ]
        );

        $this->logger->info('Supervisory authority notified of data breach', [
            'breach_id' => $dataBreach->getId(),
            'reference_number' => $dataBreach->getReferenceNumber(),
            'authority_name' => $authorityName,
            'hours_until_deadline' => $dataBreach->getHoursUntilAuthorityDeadline(),
        ]);

        return $dataBreach;
    }

    /**
     * Record delayed notification reason (Art. 33(1) GDPR)
     * Required when notification exceeds 72-hour deadline
     */
    public function recordNotificationDelay(DataBreach $dataBreach, string $delayReason): DataBreach
    {
        if (!$dataBreach->isAuthorityNotificationOverdue()) {
            throw new RuntimeException('Notification is not overdue - delay reason not needed');
        }

        $dataBreach->setNotificationDelayReason($delayReason);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.notification_delay_recorded',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'delay_reason' => $delayReason,
                'hours_late' => abs($dataBreach->getHoursUntilAuthorityDeadline()),
            ]
        );

        return $dataBreach;
    }

    /**
     * Notify data subjects (Art. 34 GDPR)
     */
    public function notifyDataSubjects(
        DataBreach $dataBreach,
        string $notificationMethod,
        int $subjectsNotified,
        array $documents = []
    ): DataBreach {
        if (!$dataBreach->getRequiresSubjectNotification()) {
            throw new RuntimeException('This data breach does not require data subject notification');
        }

        if ($dataBreach->getDataSubjectsNotifiedAt() instanceof DateTimeInterface) {
            throw new RuntimeException('Data subjects have already been notified');
        }

        $notifiedAt = new DateTime();
        $dataBreach->setDataSubjectsNotifiedAt($notifiedAt);
        $dataBreach->setSubjectNotificationMethod($notificationMethod);
        $dataBreach->setSubjectsNotified($subjectsNotified);
        $dataBreach->setSubjectNotificationDocuments($documents);
        $dataBreach->setStatus('subjects_notified');

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.subjects_notified',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'notification_method' => $notificationMethod,
                'subjects_notified' => $subjectsNotified,
                'notified_at' => $notifiedAt->format('Y-m-d H:i:s'),
            ]
        );

        $this->logger->info('Data subjects notified of data breach', [
            'breach_id' => $dataBreach->getId(),
            'reference_number' => $dataBreach->getReferenceNumber(),
            'subjects_notified' => $subjectsNotified,
        ]);

        return $dataBreach;
    }

    /**
     * Record exemption from data subject notification (Art. 34(3) GDPR)
     */
    public function recordSubjectNotificationExemption(
        DataBreach $dataBreach,
        string $exemptionReason
    ): DataBreach {
        $dataBreach->setRequiresSubjectNotification(false);
        $dataBreach->setNoSubjectNotificationReason($exemptionReason);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.subject_notification_exemption',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'exemption_reason' => $exemptionReason,
            ]
        );

        return $dataBreach;
    }

    /**
     * Close data breach investigation
     */
    public function close(DataBreach $dataBreach, User $user): DataBreach
    {
        if (!in_array($dataBreach->getStatus(), ['authority_notified', 'subjects_notified'])) {
            throw new RuntimeException('Data breach must be in authority_notified or subjects_notified status to close');
        }

        // Validate required notifications are complete
        if ($dataBreach->getRequiresAuthorityNotification() && !$dataBreach->getSupervisoryAuthorityNotifiedAt()) {
            throw new RuntimeException('Supervisory authority notification required before closing');
        }

        if ($dataBreach->getRequiresSubjectNotification() && !$dataBreach->getDataSubjectsNotifiedAt()) {
            throw new RuntimeException('Data subject notification required before closing');
        }

        $dataBreach->setStatus('closed');
        $dataBreach->setUpdatedBy($user);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.closed',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'closed_by' => $user->getEmail(),
            ]
        );

        $this->logger->info('Data breach closed', [
            'breach_id' => $dataBreach->getId(),
            'reference_number' => $dataBreach->getReferenceNumber(),
        ]);

        return $dataBreach;
    }

    /**
     * Reopen closed data breach
     */
    public function reopen(DataBreach $dataBreach, User $user, string $reopenReason): DataBreach
    {
        if ($dataBreach->getStatus() !== 'closed') {
            throw new RuntimeException('Only closed data breaches can be reopened');
        }

        $dataBreach->setStatus('under_assessment');
        $dataBreach->setUpdatedBy($user);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'data_breach.reopened',
            DataBreach::class,
            $dataBreach->getId(),
            null,
            [
                'reference_number' => $dataBreach->getReferenceNumber(),
                'reopened_by' => $user->getEmail(),
                'reopen_reason' => $reopenReason,
            ]
        );

        return $dataBreach;
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    public function findAll(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findByTenant($tenant);
    }

    public function findById(int $id): ?DataBreach
    {
        return $this->dataBreachRepository->find($id);
    }

    public function findByStatus(string $status): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findByStatus($tenant, $status);
    }

    public function findByRiskLevel(string $riskLevel): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findByRiskLevel($tenant, $riskLevel);
    }

    public function findHighRisk(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findHighRisk($tenant);
    }

    /**
     * CRITICAL: Find data breaches requiring supervisory authority notification
     */
    public function findRequiringAuthorityNotification(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findRequiringAuthorityNotification($tenant);
    }

    /**
     * CRITICAL: Find overdue notifications (>72h since detection)
     */
    public function findAuthorityNotificationOverdue(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findAuthorityNotificationOverdue($tenant);
    }

    public function findRequiringSubjectNotification(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findRequiringSubjectNotification($tenant);
    }

    public function findWithSpecialCategories(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findWithSpecialCategories($tenant);
    }

    public function findIncomplete(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findIncomplete($tenant);
    }

    public function findByProcessingActivity(ProcessingActivity $processingActivity): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findByProcessingActivity($tenant, $processingActivity->getId());
    }

    public function findRecent(int $days = 30): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        return $this->dataBreachRepository->findRecent($tenant, $days);
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
        if (!$tenant instanceof Tenant) {
            return $this->getEmptyStatistics();
        }

        return $this->dataBreachRepository->getDashboardStatistics($tenant);
    }

    /**
     * Calculate GDPR Art. 33/34 compliance score
     */
    public function calculateComplianceScore(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [
                'overall_score' => 0,
                'notification_compliance' => 0,
                'timeliness_score' => 0,
                'completeness_score' => 0,
                'overdue_notifications' => 0,
                'total_affected_subjects' => 0,
            ];
        }

        $statistics = $this->dataBreachRepository->getDashboardStatistics($tenant);
        $allBreaches = $this->dataBreachRepository->findByTenant($tenant);
        $overdueCount = count($this->dataBreachRepository->findAuthorityNotificationOverdue($tenant));

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
            'total_affected_subjects' => $this->dataBreachRepository->getTotalAffectedDataSubjects($tenant),
        ];
    }

    /**
     * Get action items for dashboard
     */
    public function getActionItems(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $items = [];

        // CRITICAL: Overdue authority notifications
        $overdueBreaches = $this->dataBreachRepository->findAuthorityNotificationOverdue($tenant);
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
        $pendingAuthority = $this->dataBreachRepository->findRequiringAuthorityNotification($tenant);
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
        $pendingSubjects = $this->dataBreachRepository->findRequiringSubjectNotification($tenant);
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
        $incompleteBreaches = $this->dataBreachRepository->findIncomplete($tenant);
        foreach ($incompleteBreaches as $incompleteBreach) {
            $items[] = [
                'priority' => 'low',
                'type' => 'incomplete',
                'breach_id' => $incompleteBreach->getId(),
                'reference_number' => $incompleteBreach->getReferenceNumber(),
                'title' => sprintf(
                    'Incomplete data breach (%d%% complete)',
                    $incompleteBreach->getCompletenessPercentage()
                ),
                'completeness' => $incompleteBreach->getCompletenessPercentage(),
            ];
        }

        // Sort by priority: critical → high → medium → low
        $priorityOrder = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
        usort($items, fn(array $a, array $b): int => $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']]);

        return $items;
    }

    private function getEmptyStatistics(): array
    {
        return [
            'total' => 0,
            'draft' => 0,
            'under_assessment' => 0,
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
