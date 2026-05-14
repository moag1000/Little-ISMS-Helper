<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\SlaDeadlineType;
use App\Service\Notification\SlaDeadlineFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SlaDeadlineFactory.
 *
 * Verifies that each factory method produces monitors with correct:
 *  - deadlineType
 *  - durationHours (deadlineAt = triggeredAt + hours)
 *  - notifyAtCheckpoints
 *  - entityType / entityId
 */
#[AllowMockObjectsWithoutExpectations]
final class SlaDeadlineFactoryTest extends TestCase
{
    private EntityManagerInterface $em;
    private SlaDeadlineFactory $factory;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->factory = new SlaDeadlineFactory($this->em);
        $this->tenant  = new Tenant();
    }

    // --- DataBreach ----------------------------------------------------------

    #[Test]
    public function createForDataBreachProducesGdpr72hMonitor(): void
    {
        $breach = $this->makeDataBreach(id: 7);

        $monitor = $this->factory->createForDataBreach($breach);

        self::assertSame(SlaDeadlineType::GdprNotification72h, $monitor->getDeadlineType());
        self::assertSame('DataBreach', $monitor->getEntityType());
        self::assertSame(7, $monitor->getEntityId());
        self::assertSame($this->tenant, $monitor->getTenant());
    }

    #[Test]
    public function createForDataBreachSetsDeadlineAt72h(): void
    {
        $triggeredAt = new DateTimeImmutable('2026-05-14 10:00:00');
        $breach      = $this->makeDataBreach(id: 7, createdAt: $triggeredAt);

        $monitor = $this->factory->createForDataBreach($breach);

        $expectedDeadline = $triggeredAt->modify('+72 hours');
        self::assertSame(
            $expectedDeadline->format('Y-m-d H:i:s'),
            $monitor->getDeadlineAt()->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function createForDataBreachSetsCorrectCheckpoints(): void
    {
        $breach  = $this->makeDataBreach(id: 7);
        $monitor = $this->factory->createForDataBreach($breach);

        self::assertSame([48, 24, 12, 4, 1], $monitor->getNotifyAtCheckpoints());
    }

    #[Test]
    public function createForDataBreachCallsEntityManagerPersist(): void
    {
        $this->em->expects(self::once())->method('persist');

        $breach = $this->makeDataBreach(id: 7);
        $this->factory->createForDataBreach($breach);
    }

    // --- Incident NIS2 -------------------------------------------------------

    #[Test]
    public function createForHighSeverityIncidentProducesNis2_24hMonitor(): void
    {
        $incident = $this->makeIncident(id: 3, severity: IncidentSeverity::High);
        $monitor  = $this->factory->createForIncident($incident, 'high');

        self::assertSame(SlaDeadlineType::Nis2EarlyWarning24h, $monitor->getDeadlineType());
        self::assertSame('Incident', $monitor->getEntityType());
        self::assertSame(3, $monitor->getEntityId());
    }

    #[Test]
    public function createForCriticalIncidentProducesNis2_24hMonitor(): void
    {
        $incident = $this->makeIncident(id: 4, severity: IncidentSeverity::Critical);
        $monitor  = $this->factory->createForIncident($incident, 'critical');

        self::assertSame(SlaDeadlineType::Nis2EarlyWarning24h, $monitor->getDeadlineType());
    }

    #[Test]
    public function createForLowSeverityIncidentProducesNis2_72hMonitor(): void
    {
        $incident = $this->makeIncident(id: 5, severity: IncidentSeverity::Low);
        $monitor  = $this->factory->createForIncident($incident, 'low');

        self::assertSame(SlaDeadlineType::Nis2Notification72h, $monitor->getDeadlineType());
    }

    #[Test]
    public function createForMediumIncidentProducesNis2_72hMonitor(): void
    {
        $incident = $this->makeIncident(id: 6, severity: IncidentSeverity::Medium);
        $monitor  = $this->factory->createForIncident($incident, 'medium');

        self::assertSame(SlaDeadlineType::Nis2Notification72h, $monitor->getDeadlineType());
        self::assertSame([48, 24, 12, 4, 1], $monitor->getNotifyAtCheckpoints());
    }

    #[Test]
    public function createForHighIncidentSetsDeadlineAt24h(): void
    {
        $detectedAt = new DateTimeImmutable('2026-05-14 10:00:00');
        $incident   = $this->makeIncident(id: 3, detectedAt: $detectedAt, severity: IncidentSeverity::High);

        $monitor = $this->factory->createForIncident($incident, 'high');

        $expectedDeadline = $detectedAt->modify('+24 hours');
        self::assertSame(
            $expectedDeadline->format('Y-m-d H:i:s'),
            $monitor->getDeadlineAt()->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function createForHighIncidentHasCorrectCheckpoints(): void
    {
        $incident = $this->makeIncident(id: 3, severity: IncidentSeverity::High);
        $monitor  = $this->factory->createForIncident($incident, 'high');

        self::assertSame([12, 4, 1], $monitor->getNotifyAtCheckpoints());
    }

    // --- AuditFinding corrective action --------------------------------------

    #[Test]
    public function createForCorrectiveActionProducesIso30dMonitor(): void
    {
        $finding = $this->makeAuditFinding(id: 11);
        $monitor = $this->factory->createForCorrectiveAction($finding);

        self::assertSame(SlaDeadlineType::IsoCorrectiveAction30d, $monitor->getDeadlineType());
        self::assertSame('AuditFinding', $monitor->getEntityType());
        self::assertSame(11, $monitor->getEntityId());
    }

    #[Test]
    public function createForCorrectiveActionSetsDeadlineAt720h(): void
    {
        $createdAt = new DateTimeImmutable('2026-05-01 00:00:00');
        $finding   = $this->makeAuditFinding(id: 11, createdAt: $createdAt);

        $monitor = $this->factory->createForCorrectiveAction($finding);

        // 30 days = 720 hours
        $expectedDeadline = $createdAt->modify('+720 hours');
        self::assertSame(
            $expectedDeadline->format('Y-m-d H:i:s'),
            $monitor->getDeadlineAt()->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function createForCorrectiveActionHasCorrectCheckpoints(): void
    {
        $finding = $this->makeAuditFinding(id: 11);
        $monitor = $this->factory->createForCorrectiveAction($finding);

        // 10d (240h), 7d (168h), 3d (72h), 1d (24h)
        self::assertSame([240, 168, 72, 24], $monitor->getNotifyAtCheckpoints());
    }

    // --- Helpers -------------------------------------------------------------

    private function makeDataBreach(int $id, ?DateTimeImmutable $createdAt = null): DataBreach
    {
        $breach = new DataBreach();

        // Inject tenant via reflection (no public setter for id)
        $r = new \ReflectionProperty(DataBreach::class, 'id');
        $r->setValue($breach, $id);

        $breach->setTenant($this->tenant);

        if ($createdAt !== null) {
            $breach->setCreatedAt($createdAt);
        }

        return $breach;
    }

    private function makeIncident(
        int $id,
        ?DateTimeImmutable $detectedAt = null,
        ?IncidentSeverity $severity = null,
    ): Incident {
        $incident = new Incident();

        $r = new \ReflectionProperty(Incident::class, 'id');
        $r->setValue($incident, $id);

        $incident->setTenant($this->tenant);

        if ($detectedAt !== null) {
            $incident->setDetectedAt($detectedAt);
        }

        if ($severity !== null) {
            $incident->setSeverity($severity);
        }

        return $incident;
    }

    private function makeAuditFinding(int $id, ?DateTimeImmutable $createdAt = null): AuditFinding
    {
        $finding = new AuditFinding();

        $r = new \ReflectionProperty(AuditFinding::class, 'id');
        $r->setValue($finding, $id);

        $finding->setTenant($this->tenant);

        if ($createdAt !== null) {
            $r2 = new \ReflectionProperty(AuditFinding::class, 'createdAt');
            $r2->setValue($finding, $createdAt);
        }

        return $finding;
    }
}
