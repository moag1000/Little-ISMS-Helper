<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Enum\SlaDeadlineType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SLA Deadline Factory — Sprint 7A F3 Wave 2
 *
 * Constructs and persists SlaDeadlineMonitor instances for well-known
 * regulatory deadlines. The caller (App\EventListener\SlaDeadlineMonitorSpawner)
 * uses these factory methods instead of assembling monitors manually to ensure
 * consistent checkpoint arrays and deadline calculations.
 *
 * Compliance references:
 *  - DataBreach → GDPR Art. 33 72h
 *  - Incident (high/critical + NIS2 scope) → NIS2 Art. 23 24h/72h
 *  - AuditFinding → ISO 27001 Cl. 10.1 30-day corrective action
 */
final class SlaDeadlineFactory
{
    /** Default checkpoints for the GDPR 72h deadline (hours before deadline). */
    private const array CHECKPOINTS_GDPR_72H = [48, 24, 12, 4, 1];

    /** Default checkpoints for NIS2 24h early warning. */
    private const array CHECKPOINTS_NIS2_24H = [12, 4, 1];

    /** Default checkpoints for NIS2 72h notification. */
    private const array CHECKPOINTS_NIS2_72H = [48, 24, 12, 4, 1];

    /** Default checkpoints for the ISO Cl. 10.1 30-day corrective action deadline. */
    private const array CHECKPOINTS_ISO_30D = [240, 168, 72, 24]; // 10d, 7d, 3d, 1d in hours

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Whether $entity needs a regulatory SLA monitor — the one rule the
     * spawner (on flush) and the cron retry ({@see SlaDeadlineMonitorRetry})
     * share:
     *  - every DataBreach,
     *  - an Incident with notificationRequired or a NIS2 category,
     *  - an AuditFinding of type major / minor nonconformity.
     */
    public static function isRelevant(object $entity): bool
    {
        return match (true) {
            $entity instanceof DataBreach => true,
            $entity instanceof Incident => $entity->isNotificationRequired() === true
                || $entity->getNis2Category() !== null,
            $entity instanceof AuditFinding => in_array(
                $entity->getType(),
                [AuditFinding::TYPE_MAJOR_NC, AuditFinding::TYPE_MINOR_NC],
                true,
            ),
            default => false,
        };
    }

    /**
     * Monitor entity-type key of $entity ('DataBreach' / 'Incident' /
     * 'AuditFinding'), null for any other object.
     */
    public static function entityTypeOf(object $entity): ?string
    {
        return match (true) {
            $entity instanceof DataBreach => 'DataBreach',
            $entity instanceof Incident => 'Incident',
            $entity instanceof AuditFinding => 'AuditFinding',
            default => null,
        };
    }

    /**
     * Build — without persisting — the monitor $entity needs (see
     * {@see isRelevant()} for who needs one), or null for an unsupported
     * object.
     */
    public function buildFor(object $entity): ?SlaDeadlineMonitor
    {
        return match (true) {
            $entity instanceof DataBreach => $this->buildForDataBreach($entity),
            $entity instanceof Incident => $this->buildForIncident($entity, $entity->getSeverity()->value ?? 'low'),
            $entity instanceof AuditFinding => $this->buildForCorrectiveAction($entity),
            default => null,
        };
    }

    /**
     * Create a GDPR Art. 33 72h notification deadline for a DataBreach.
     *
     * Checkpoints: 48h, 24h, 12h, 4h, 1h before deadline.
     * triggeredAt = breach.createdAt (or now if unavailable).
     */
    public function createForDataBreach(DataBreach $breach): SlaDeadlineMonitor
    {
        $monitor = $this->buildForDataBreach($breach);
        $this->entityManager->persist($monitor);

        return $monitor;
    }

    private function buildForDataBreach(DataBreach $breach): SlaDeadlineMonitor
    {
        $triggeredAt = $breach->getCreatedAt() !== null
            ? DateTimeImmutable::createFromInterface($breach->getCreatedAt())
            : new DateTimeImmutable();

        $deadlineType = SlaDeadlineType::GdprNotification72h;
        $deadlineAt   = $triggeredAt->modify('+' . $deadlineType->durationHours() . ' hours');

        $monitor = new SlaDeadlineMonitor();
        $monitor->setTenant($breach->getTenant());
        $monitor->setEntityType('DataBreach');
        $monitor->setEntityId((int) $breach->getId());
        $monitor->setDeadlineType($deadlineType);
        $monitor->setTriggeredAt($triggeredAt);
        $monitor->setDeadlineAt($deadlineAt);
        $monitor->setNotifyAtCheckpoints(self::CHECKPOINTS_GDPR_72H);

        return $monitor;
    }

    /**
     * Create an NIS2 Art. 23 deadline monitor for an Incident.
     *
     * Severity mapping:
     *  - critical, high  → NIS2 24h early warning (most urgent)
     *  - medium, low     → NIS2 72h notification
     *
     * triggeredAt = incident.detectedAt (or now if unavailable).
     *
     * @param string $criticality  'critical'|'high'|'medium'|'low'
     */
    public function createForIncident(Incident $incident, string $criticality): SlaDeadlineMonitor
    {
        $monitor = $this->buildForIncident($incident, $criticality);
        $this->entityManager->persist($monitor);

        return $monitor;
    }

    private function buildForIncident(Incident $incident, string $criticality): SlaDeadlineMonitor
    {
        $detectedAt = $incident->getDetectedAt() !== null
            ? DateTimeImmutable::createFromInterface($incident->getDetectedAt())
            : new DateTimeImmutable();

        $isHighSeverity = in_array($criticality, ['critical', 'high'], true);

        $deadlineType = $isHighSeverity
            ? SlaDeadlineType::Nis2EarlyWarning24h
            : SlaDeadlineType::Nis2Notification72h;

        $checkpoints = $isHighSeverity
            ? self::CHECKPOINTS_NIS2_24H
            : self::CHECKPOINTS_NIS2_72H;

        $deadlineAt = $detectedAt->modify('+' . $deadlineType->durationHours() . ' hours');

        $monitor = new SlaDeadlineMonitor();
        $monitor->setTenant($incident->getTenant());
        $monitor->setEntityType('Incident');
        $monitor->setEntityId((int) $incident->getId());
        $monitor->setDeadlineType($deadlineType);
        $monitor->setTriggeredAt($detectedAt);
        $monitor->setDeadlineAt($deadlineAt);
        $monitor->setNotifyAtCheckpoints($checkpoints);

        return $monitor;
    }

    /**
     * Create an ISO 27001 Cl. 10.1 30-day corrective-action deadline for an AuditFinding.
     *
     * Checkpoints: 10 days (240h), 7 days (168h), 3 days (72h), 1 day (24h) before deadline.
     * triggeredAt = finding.createdAt (or now if unavailable).
     */
    public function createForCorrectiveAction(AuditFinding $finding): SlaDeadlineMonitor
    {
        $monitor = $this->buildForCorrectiveAction($finding);
        $this->entityManager->persist($monitor);

        return $monitor;
    }

    private function buildForCorrectiveAction(AuditFinding $finding): SlaDeadlineMonitor
    {
        $createdAt = $finding->getCreatedAt() !== null
            ? DateTimeImmutable::createFromInterface($finding->getCreatedAt())
            : new DateTimeImmutable();

        $deadlineType = SlaDeadlineType::IsoCorrectiveAction30d;
        $deadlineAt   = $createdAt->modify('+' . $deadlineType->durationHours() . ' hours');

        $monitor = new SlaDeadlineMonitor();
        $monitor->setTenant($finding->getTenant());
        $monitor->setEntityType('AuditFinding');
        $monitor->setEntityId((int) $finding->getId());
        $monitor->setDeadlineType($deadlineType);
        $monitor->setTriggeredAt($createdAt);
        $monitor->setDeadlineAt($deadlineAt);
        $monitor->setNotifyAtCheckpoints(self::CHECKPOINTS_ISO_30D);

        return $monitor;
    }
}
