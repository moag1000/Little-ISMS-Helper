<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use App\Service\Incident\ReportingPlanner;
use App\Service\ModuleConfigurationService;
use App\Service\Notification\SlaDeadlineFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SlaDeadlineFactory keeps one monitor per statutory report of a record in
 * line with the ReportingPlanner, and one ISO 10.1 monitor per nonconformity.
 */
final class SlaDeadlineFactoryTest extends TestCase
{
    private SlaDeadlineFactory $factory;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturn(true);
        $this->factory = new SlaDeadlineFactory(new ReportingPlanner($modules));
        $this->tenant = new Tenant();
    }

    #[Test]
    public function dataBreachClockStartsAtAwarenessNotAtRecordCreation(): void
    {
        $breach = $this->withId(new DataBreach(), 7);
        $breach->setDetectedAt(new DateTimeImmutable('2026-05-01 10:00:00'));
        $breach->setCreatedAt(new DateTimeImmutable('2026-05-02 09:00:00'));

        [$monitor] = $this->factory->sync($breach, [])['new'];

        self::assertSame(SlaDeadlineType::GdprNotification72h, $monitor->getDeadlineType());
        self::assertSame('DataBreach', $monitor->getEntityType());
        self::assertSame(7, $monitor->getEntityId());
        self::assertSame('2026-05-04 10:00:00', $monitor->getDeadlineAt()->format('Y-m-d H:i:s'));
        self::assertSame([48, 24, 12, 4, 1], $monitor->getNotifyAtCheckpoints());
    }

    #[Test]
    public function breachWithoutAuthorityNotificationHasNoClock(): void
    {
        $breach = $this->withId(new DataBreach(), 8);
        $breach->setRequiresAuthorityNotification(false);

        self::assertSame([], $this->factory->sync($breach, [])['new']);
    }

    #[Test]
    public function reportableIncidentGetsThreeNis2Clocks(): void
    {
        $incident = $this->incident(3, IncidentSeverity::High);

        $types = array_map(
            static fn (SlaDeadlineMonitor $m): SlaDeadlineType => $m->getDeadlineType(),
            $this->factory->sync($incident, [])['new'],
        );

        self::assertSame([SlaDeadlineType::Nis2EarlyWarning24h, SlaDeadlineType::Nis2Notification72h, SlaDeadlineType::Nis2FinalReport1mo], $types);
    }

    #[Test]
    public function lateReportMarksTheClockMissedInTimeReportSatisfiesIt(): void
    {
        $incident = $this->incident(4, IncidentSeverity::High);
        $incident->setEarlyWarningReportedAt(new DateTimeImmutable('2026-05-01 12:00')); // in time
        $incident->setDetailedNotificationReportedAt(new DateTimeImmutable('2026-05-06 08:00')); // late

        $monitors = $this->factory->sync($incident, [])['new'];

        self::assertSame(SlaDeadlineStatus::Satisfied, $monitors[0]->getStatus());
        self::assertSame(SlaDeadlineStatus::Missed, $monitors[1]->getStatus());
        self::assertSame(SlaDeadlineStatus::Active, $monitors[2]->getStatus());
        // Final report: one calendar month after the actual notification.
        self::assertSame('2026-06-06 08:00', $monitors[2]->getDeadlineAt()->format('Y-m-d H:i'));
    }

    #[Test]
    public function nonconformityGetsTheIso30dClockOnce(): void
    {
        $finding = $this->withId(new AuditFinding(), 11);
        $finding->setType(AuditFinding::TYPE_MAJOR_NC);
        (new \ReflectionProperty(AuditFinding::class, 'createdAt'))->setValue($finding, new DateTimeImmutable('2026-05-01 00:00:00'));

        [$monitor] = $this->factory->sync($finding, [])['new'];

        self::assertSame(SlaDeadlineType::IsoCorrectiveAction30d, $monitor->getDeadlineType());
        self::assertSame('2026-05-31 00:00:00', $monitor->getDeadlineAt()->format('Y-m-d H:i:s'));
        self::assertSame([240, 168, 72, 24], $monitor->getNotifyAtCheckpoints());
        self::assertSame([], $this->factory->sync($finding, [$monitor])['new']);
    }

    private function incident(int $id, IncidentSeverity $severity): Incident
    {
        $incident = $this->withId(new Incident(), $id);
        $incident->setSeverity($severity);
        $incident->setDetectedAt(new DateTimeImmutable('2026-05-01 08:00:00'));

        return $incident;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
        $entity->setTenant($this->tenant);

        return $entity;
    }
}
