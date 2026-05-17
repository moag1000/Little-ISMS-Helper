<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Enum\SlaDeadlineStatus;
use App\Repository\Notification\NotificationRuleRepository;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * SLA Deadline Watcher — Sprint 7A F3 Wave 2
 *
 * Cron-driven service that iterates over all tenants and:
 *   1. Fires approaching-deadline notifications at each configured checkpoint.
 *   2. Marks overdue monitors as 'missed' and fires a critical notification.
 *
 * Called by ProcessTimedWorkflowsCommand, which is recommended to run every 15 minutes.
 *
 * Notification approach:
 *   Watcher dispatches via NotificationDispatcher against notification rules
 *   matching the canonical SLA event types:
 *     - approaching: 'notification.sla.deadline_approaching'
 *     - missed:      'notification.sla.deadline_missed'
 *
 *   If no matching NotificationRule is configured for the tenant, the watcher
 *   logs an audit entry but does not dispatch (graceful degradation).
 *
 * Not marked final — tests mock this class.
 */
final class SlaDeadlineWatcher
{
    /**
     * Look-ahead window for the approaching-deadline query (hours).
     * Must be >= the largest checkpoint value across all monitor types.
     * 48h covers GDPR 72h and NIS2 72h monitors at their earliest checkpoint (48h).
     */
    private const int LOOKAHEAD_HOURS = 48;

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly SlaDeadlineMonitorRepository $monitorRepository,
        private readonly NotificationRuleRepository $ruleRepository,
        private readonly NotificationDispatcher $dispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Main entry point — iterate all tenants, tick approaching + missed monitors.
     *
     * Returns a summary array for command output.
     *
     * @return array{approached: int, missed: int, errors: string[]}
     */
    public function tickAll(): array
    {
        $tenants   = $this->tenantRepository->findActive();
        $approached = 0;
        $missed     = 0;
        $errors     = [];

        foreach ($tenants as $tenant) {
            try {
                $result  = $this->tickTenant($tenant);
                $approached += $result['approached'];
                $missed     += $result['missed'];
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Tenant #%d (%s): %s',
                    (int) $tenant->getId(),
                    (string) $tenant->getName(),
                    $e->getMessage(),
                );
                $this->logger->error('SlaDeadlineWatcher: tenant tick failed', [
                    'tenant_id' => $tenant->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return ['approached' => $approached, 'missed' => $missed, 'errors' => $errors];
    }

    /**
     * Process a single tenant.
     *
     * @return array{approached: int, missed: int}
     */
    private function tickTenant(Tenant $tenant): array
    {
        $approached = 0;
        $missed     = 0;

        // --- 1. Approaching checkpoints ---
        $approaching = $this->monitorRepository->findApproachingDeadlines($tenant, self::LOOKAHEAD_HOURS);

        foreach ($approaching as $monitor) {
            if ($this->fireApproachingCheckpoint($monitor, $tenant)) {
                $approached++;
            }
        }

        // --- 2. Missed deadlines ---
        $missedMonitors = $this->monitorRepository->findMissedDeadlines($tenant);

        foreach ($missedMonitors as $monitor) {
            $this->markMissedAndNotify($monitor, $tenant);
            $missed++;
        }

        if ($approaching || $missedMonitors) {
            $this->entityManager->flush();
        }

        return ['approached' => $approached, 'missed' => $missed];
    }

    /**
     * Fire an approaching checkpoint notification if one is due.
     *
     * Returns true when a notification was actually dispatched.
     */
    private function fireApproachingCheckpoint(SlaDeadlineMonitor $monitor, Tenant $tenant): bool
    {
        $hoursRemaining = (int) ceil($monitor->hoursRemaining());

        // Find the smallest checkpoint that has NOT yet been notified and is >= current hours remaining
        $pendingCheckpoint = $this->selectPendingCheckpoint($monitor, $hoursRemaining);

        if ($pendingCheckpoint === null) {
            return false; // All applicable checkpoints already fired
        }

        $this->logger->info('SlaDeadlineWatcher: approaching checkpoint', [
            'monitor_id'   => $monitor->getId(),
            'entity_type'  => $monitor->getEntityType(),
            'entity_id'    => $monitor->getEntityId(),
            'checkpoint_h' => $pendingCheckpoint,
            'hours_left'   => $hoursRemaining,
        ]);

        // Dispatch via NotificationRule if one exists for this event type
        $this->dispatchSlaEvent(
            AuditLogger::ACTION_SLA_DEADLINE_APPROACHING,
            $monitor,
            $tenant,
            ['severity' => 'warning', 'checkpoint_hours' => $pendingCheckpoint, 'hours_remaining' => $hoursRemaining],
        );

        // Update lastNotifiedAtHours so this checkpoint is not re-fired
        $monitor->setLastNotifiedAtHours($pendingCheckpoint);

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_SLA_DEADLINE_APPROACHING,
            'SlaDeadlineMonitor',
            $monitor->getId(),
            null,
            [
                'entity_type'   => $monitor->getEntityType(),
                'entity_id'     => $monitor->getEntityId(),
                'deadline_type' => $monitor->getDeadlineType()->value,
                'checkpoint_h'  => $pendingCheckpoint,
            ],
            sprintf(
                'SLA approaching: %s #%d — %dh checkpoint',
                $monitor->getEntityType(),
                $monitor->getEntityId(),
                $pendingCheckpoint,
            ),
        );

        return true;
    }

    /**
     * Transition monitor to 'missed' and dispatch a critical notification.
     */
    private function markMissedAndNotify(SlaDeadlineMonitor $monitor, Tenant $tenant): void
    {
        $monitor->setStatus(SlaDeadlineStatus::Missed);

        $this->logger->warning('SlaDeadlineWatcher: deadline missed', [
            'monitor_id'  => $monitor->getId(),
            'entity_type' => $monitor->getEntityType(),
            'entity_id'   => $monitor->getEntityId(),
            'deadline_at' => $monitor->getDeadlineAt()->format('Y-m-d H:i:s'),
        ]);

        $this->dispatchSlaEvent(
            AuditLogger::ACTION_SLA_DEADLINE_MISSED,
            $monitor,
            $tenant,
            ['severity' => 'critical'],
        );

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_SLA_DEADLINE_MISSED,
            'SlaDeadlineMonitor',
            $monitor->getId(),
            null,
            [
                'entity_type'   => $monitor->getEntityType(),
                'entity_id'     => $monitor->getEntityId(),
                'deadline_type' => $monitor->getDeadlineType()->value,
                'deadline_at'   => $monitor->getDeadlineAt()->format('Y-m-d H:i:s'),
            ],
            sprintf(
                'SLA MISSED: %s #%d — %s',
                $monitor->getEntityType(),
                $monitor->getEntityId(),
                $monitor->getDeadlineType()->label(),
            ),
        );
    }

    /**
     * Dispatch via the NotificationDispatcher if a matching rule exists.
     * Gracefully no-ops if no rule is configured for this event type.
     *
     * @param array<string, mixed> $entityState
     */
    private function dispatchSlaEvent(
        string $eventType,
        SlaDeadlineMonitor $monitor,
        Tenant $tenant,
        array $entityState,
    ): void {
        $rules = $this->ruleRepository->findActiveByEventType($eventType, $tenant);

        $state = array_merge($entityState, [
            'entity_type'   => $monitor->getEntityType(),
            'entity_id'     => $monitor->getEntityId(),
            'deadline_type' => $monitor->getDeadlineType()->value,
            'deadline_at'   => $monitor->getDeadlineAt()->format('c'),
        ]);

        foreach ($rules as $rule) {
            try {
                $this->dispatcher->dispatch($rule, $state);
            } catch (\Throwable $e) {
                $this->logger->error('SlaDeadlineWatcher: dispatch failed for rule', [
                    'rule_id' => $rule->getId(),
                    'event'   => $eventType,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Select the next pending checkpoint for this monitor.
     *
     * Logic: from the list of configured checkpoint hours-before values,
     * find the largest checkpoint that is:
     *   - less than or equal to current hours-remaining (i.e. threshold has been crossed), AND
     *   - greater than lastNotifiedAtHours (i.e. not yet emitted).
     *
     * This ensures exactly one checkpoint fires per cron tick per crossing.
     */
    private function selectPendingCheckpoint(SlaDeadlineMonitor $monitor, int $hoursRemaining): ?int
    {
        $checkpoints         = $monitor->getNotifyAtCheckpoints();
        $lastNotifiedAtHours = $monitor->getLastNotifiedAtHours();

        // Sort descending so we pick the largest not-yet-fired checkpoint first
        rsort($checkpoints);

        foreach ($checkpoints as $checkpoint) {
            // Checkpoint threshold crossed when hoursRemaining <= checkpoint
            if ($hoursRemaining > $checkpoint) {
                continue; // Not yet reached this checkpoint
            }

            // Skip if already fired (lastNotifiedAtHours tracks the smallest emitted checkpoint)
            if ($lastNotifiedAtHours !== null && $checkpoint >= $lastNotifiedAtHours) {
                continue;
            }

            return $checkpoint;
        }

        return null;
    }
}
