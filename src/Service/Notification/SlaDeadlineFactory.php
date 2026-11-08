<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use App\Service\Incident\ReportingDuty;
use App\Service\Incident\ReportingPlanner;
use DateTimeImmutable;

/**
 * SLA Deadline Factory
 *
 * Keeps the SlaDeadlineMonitor rows of a record in line with its duties:
 *  - Incident / DataBreach → one monitor per statutory report
 *    ({@see ReportingPlanner}: NIS-2 24 h / 72 h / final, DORA initial /
 *    intermediate / final, GDPR 72 h). A recorded report satisfies its
 *    monitor (or marks it missed when it came late); a duty that no longer
 *    applies dismisses its running monitor.
 *  - AuditFinding (major/minor NC) → ISO 27001 Cl. 10.1 30-day corrective action.
 *
 * Callers: App\EventListener\SlaDeadlineMonitorSpawner (on flush) and
 * {@see SlaDeadlineMonitorRetry} (cron safety net).
 */
final readonly class SlaDeadlineFactory
{
    /** Checkpoints (hours before the deadline) per deadline type. */
    private const array CHECKPOINTS = [
        'gdpr_72h' => [48, 24, 12, 4, 1],
        'nis2_24h' => [12, 4, 1],
        'nis2_72h' => [48, 24, 12, 4, 1],
        'nis2_1mo' => [168, 72, 24],
        'nis2_interim' => [24, 4, 1],
        'dora_4h' => [12, 4, 1],
        'dora_72h' => [48, 24, 12, 4, 1],
        'dora_1mo' => [168, 72, 24],
        'iso_corrective_action_30d' => [240, 168, 72, 24],
    ];

    public function __construct(
        private ReportingPlanner $planner,
    ) {}

    /**
     * Whether $entity can carry SLA monitors at all — a cheap filter the
     * spawner and the retry share; the planner decides which duties apply.
     */
    public static function isRelevant(object $entity): bool
    {
        return match (true) {
            $entity instanceof DataBreach, $entity instanceof Incident => true,
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
     * Bring the monitors of $entity in line with its duties. Existing
     * monitors are updated in place; the new ones are returned unpersisted.
     *
     * @param list<SlaDeadlineMonitor> $existing the entity's current monitors
     *
     * @return array{new: list<SlaDeadlineMonitor>, changed: bool}
     */
    public function sync(object $entity, array $existing): array
    {
        $type = self::entityTypeOf($entity);
        if ($type === null || $entity->getTenant() === null || $entity->getId() === null) {
            return ['new' => [], 'changed' => false];
        }

        if ($entity instanceof AuditFinding) {
            return $existing === []
                ? ['new' => [$this->buildForCorrectiveAction($entity)], 'changed' => true]
                : ['new' => [], 'changed' => false];
        }

        $byType = [];
        foreach ($existing as $monitor) {
            $byType[$monitor->getDeadlineType()->value] = $monitor;
        }

        $new = [];
        $changed = false;
        foreach ($this->planner->dutiesFor($entity) as $duty) {
            $monitor = $byType[$duty->type->value] ?? null;
            unset($byType[$duty->type->value]);
            if ($monitor === null) {
                $monitor = new SlaDeadlineMonitor();
                $monitor->setTenant($entity->getTenant());
                $monitor->setEntityType($type);
                $monitor->setEntityId((int) $entity->getId());
                $monitor->setDeadlineType($duty->type);
                $monitor->setNotifyAtCheckpoints(self::CHECKPOINTS[$duty->type->value] ?? []);
                $new[] = $monitor;
            }
            $changed = self::apply($monitor, $duty) || $changed;
        }

        // Duties that no longer apply (assessed as not reportable, DORA
        // classification withdrawn): stop their running clocks.
        foreach ($byType as $monitor) {
            $unreported = $monitor->getSatisfiedAt() === null
                && in_array($monitor->getStatus(), [SlaDeadlineStatus::Active, SlaDeadlineStatus::Missed], true);
            if ($unreported) {
                $monitor->setStatus(SlaDeadlineStatus::Dismissed);
                $changed = true;
            }
        }

        return ['new' => $new, 'changed' => $changed || $new !== []];
    }

    /** Apply the duty's dates and report state to the monitor; true when something changed. */
    private static function apply(SlaDeadlineMonitor $monitor, ReportingDuty $duty): bool
    {
        $before = [
            $monitor->getStatus(),
            $monitor->getSatisfiedAt()?->format('c'),
            $monitor->getDeadlineAt()->format('c'),
            $monitor->getTriggeredAt()->format('c'),
        ];

        $monitor->setTriggeredAt($duty->anchor);
        $monitor->setDeadlineAt($duty->deadline);
        if ($duty->reportedAt !== null) {
            $monitor->setSatisfiedAt($duty->reportedAt);
            $monitor->setStatus($duty->isReportedInTime() ? SlaDeadlineStatus::Satisfied : SlaDeadlineStatus::Missed);
        } else {
            $monitor->setSatisfiedAt(null);
            if ($monitor->getStatus() !== SlaDeadlineStatus::Missed || $duty->deadline > new DateTimeImmutable()) {
                // A moved deadline or a withdrawn report re-opens the clock;
                // the watcher marks it missed again once the deadline passed.
                $monitor->setStatus(SlaDeadlineStatus::Active);
            }
        }

        return $before !== [
            $monitor->getStatus(),
            $monitor->getSatisfiedAt()?->format('c'),
            $monitor->getDeadlineAt()->format('c'),
            $monitor->getTriggeredAt()->format('c'),
        ];
    }

    private function buildForCorrectiveAction(AuditFinding $finding): SlaDeadlineMonitor
    {
        $createdAt = $finding->getCreatedAt() !== null
            ? DateTimeImmutable::createFromInterface($finding->getCreatedAt())
            : new DateTimeImmutable();

        $deadlineType = SlaDeadlineType::IsoCorrectiveAction30d;

        $monitor = new SlaDeadlineMonitor();
        $monitor->setTenant($finding->getTenant());
        $monitor->setEntityType('AuditFinding');
        $monitor->setEntityId((int) $finding->getId());
        $monitor->setDeadlineType($deadlineType);
        $monitor->setTriggeredAt($createdAt);
        $monitor->setDeadlineAt($createdAt->modify('+' . $deadlineType->durationHours() . ' hours'));
        $monitor->setNotifyAtCheckpoints(self::CHECKPOINTS[$deadlineType->value]);

        return $monitor;
    }
}
