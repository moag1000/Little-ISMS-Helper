<?php

declare(strict_types=1);

namespace App\Tests\Entity\Notification;

use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SlaDeadlineMonitor entity accessors and domain helpers.
 */
final class SlaDeadlineMonitorTest extends TestCase
{
    private SlaDeadlineMonitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new SlaDeadlineMonitor();
    }

    #[Test]
    public function defaultStatusIsActive(): void
    {
        self::assertSame(SlaDeadlineStatus::Active, $this->monitor->getStatus());
    }

    #[Test]
    public function defaultDeadlineTypeIsCustom(): void
    {
        self::assertSame(SlaDeadlineType::Custom, $this->monitor->getDeadlineType());
    }

    #[Test]
    public function defaultCheckpointsIsEmptyArray(): void
    {
        self::assertSame([], $this->monitor->getNotifyAtCheckpoints());
    }

    #[Test]
    public function defaultLastNotifiedAtHoursIsNull(): void
    {
        self::assertNull($this->monitor->getLastNotifiedAtHours());
    }

    #[Test]
    public function setTenantAndGet(): void
    {
        $tenant = new Tenant();
        $this->monitor->setTenant($tenant);
        self::assertSame($tenant, $this->monitor->getTenant());
    }

    #[Test]
    public function setEntityTypeAndId(): void
    {
        $this->monitor->setEntityType('DataBreach');
        $this->monitor->setEntityId(42);

        self::assertSame('DataBreach', $this->monitor->getEntityType());
        self::assertSame(42, $this->monitor->getEntityId());
    }

    #[Test]
    public function setDeadlineType(): void
    {
        $this->monitor->setDeadlineType(SlaDeadlineType::GdprNotification72h);
        self::assertSame(SlaDeadlineType::GdprNotification72h, $this->monitor->getDeadlineType());
    }

    #[Test]
    public function setTriggeredAtAndDeadlineAt(): void
    {
        $triggered = new DateTimeImmutable('2026-05-14 00:00:00');
        $deadline  = new DateTimeImmutable('2026-05-17 00:00:00');

        $this->monitor->setTriggeredAt($triggered);
        $this->monitor->setDeadlineAt($deadline);

        self::assertSame($triggered, $this->monitor->getTriggeredAt());
        self::assertSame($deadline, $this->monitor->getDeadlineAt());
    }

    #[Test]
    public function setCheckpoints(): void
    {
        $checkpoints = [48, 24, 12, 4, 1];
        $this->monitor->setNotifyAtCheckpoints($checkpoints);
        self::assertSame($checkpoints, $this->monitor->getNotifyAtCheckpoints());
    }

    #[Test]
    public function setLastNotifiedAtHours(): void
    {
        $this->monitor->setLastNotifiedAtHours(24);
        self::assertSame(24, $this->monitor->getLastNotifiedAtHours());
    }

    #[Test]
    public function setStatusToMissed(): void
    {
        $this->monitor->setStatus(SlaDeadlineStatus::Missed);
        self::assertTrue($this->monitor->isMissed());
        self::assertFalse($this->monitor->isActive());
        self::assertFalse($this->monitor->isSatisfied());
    }

    #[Test]
    public function setStatusToSatisfied(): void
    {
        $this->monitor->setStatus(SlaDeadlineStatus::Satisfied);
        self::assertTrue($this->monitor->isSatisfied());
        self::assertFalse($this->monitor->isMissed());
    }

    #[Test]
    public function satisfiedAtAndSatisfiedBy(): void
    {
        $user = new User();
        $now  = new DateTimeImmutable();

        $this->monitor->setSatisfiedAt($now);
        $this->monitor->setSatisfiedBy($user);

        self::assertSame($now, $this->monitor->getSatisfiedAt());
        self::assertSame($user, $this->monitor->getSatisfiedBy());
    }

    #[Test]
    public function hoursRemainingIsPositiveForFutureDeadline(): void
    {
        $future = new DateTimeImmutable('+10 hours');
        $this->monitor->setDeadlineAt($future);

        self::assertGreaterThan(0.0, $this->monitor->hoursRemaining());
    }

    #[Test]
    public function hoursRemainingIsNegativeForPastDeadline(): void
    {
        $past = new DateTimeImmutable('-5 hours');
        $this->monitor->setDeadlineAt($past);

        self::assertLessThan(0.0, $this->monitor->hoursRemaining());
    }

    #[Test]
    public function isActiveReturnsTrueByDefault(): void
    {
        self::assertTrue($this->monitor->isActive());
    }

    #[Test]
    public function fluentSettersReturnStatic(): void
    {
        $result = $this->monitor
            ->setEntityType('Incident')
            ->setEntityId(1)
            ->setDeadlineType(SlaDeadlineType::Nis2EarlyWarning24h)
            ->setNotifyAtCheckpoints([12, 4, 1])
            ->setLastNotifiedAtHours(12)
            ->setStatus(SlaDeadlineStatus::Active);

        self::assertSame($this->monitor, $result);
    }
}
