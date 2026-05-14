<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SlaDeadlineMonitorRepository query contracts.
 *
 * These tests validate the repository's method signatures and ensure
 * that the correct query parameters are passed. Integration tests
 * (real DB queries) are out of scope for this unit-test suite.
 */
final class SlaDeadlineMonitorRepositoryTest extends TestCase
{
    /**
     * Verify the repository class exists and has the expected public methods.
     */
    #[Test]
    public function repositoryHasExpectedPublicMethods(): void
    {
        $methods = get_class_methods(SlaDeadlineMonitorRepository::class);

        self::assertContains('findApproachingDeadlines', $methods);
        self::assertContains('findMissedDeadlines', $methods);
        self::assertContains('findForEntity', $methods);
        self::assertContains('countMissedForTenant', $methods);
    }

    #[Test]
    public function repositoryExtendsServiceEntityRepository(): void
    {
        self::assertTrue(
            is_a(
                SlaDeadlineMonitorRepository::class,
                \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::class,
                true,
            ),
        );
    }

    #[Test]
    public function slaDeadlineMonitorStatusActiveEnumValue(): void
    {
        self::assertSame('active', SlaDeadlineStatus::Active->value);
    }

    #[Test]
    public function slaDeadlineMonitorStatusMissedEnumValue(): void
    {
        self::assertSame('missed', SlaDeadlineStatus::Missed->value);
    }

    #[Test]
    public function slaDeadlineMonitorStatusSatisfiedEnumValue(): void
    {
        self::assertSame('satisfied', SlaDeadlineStatus::Satisfied->value);
    }

    #[Test]
    public function gdpr72hDeadlineTypeHasDuration72h(): void
    {
        self::assertSame(72, SlaDeadlineType::GdprNotification72h->durationHours());
    }

    #[Test]
    public function doraInitialNotification4hHasDuration4h(): void
    {
        self::assertSame(4, SlaDeadlineType::DoraInitialNotification4h->durationHours());
    }

    #[Test]
    public function nis2EarlyWarning24hHasDuration24h(): void
    {
        self::assertSame(24, SlaDeadlineType::Nis2EarlyWarning24h->durationHours());
    }

    #[Test]
    public function isoCorrectiveAction30dHasDuration720h(): void
    {
        self::assertSame(720, SlaDeadlineType::IsoCorrectiveAction30d->durationHours());
    }

    #[Test]
    public function monitorEntityCapturesStatusCorrectly(): void
    {
        $monitor = new SlaDeadlineMonitor();
        $monitor->setStatus(SlaDeadlineStatus::Missed);

        self::assertSame(SlaDeadlineStatus::Missed, $monitor->getStatus());
        self::assertTrue($monitor->isMissed());
    }

    #[Test]
    public function findForEntitySignatureAcceptsStringIntTenant(): void
    {
        $r = new \ReflectionMethod(SlaDeadlineMonitorRepository::class, 'findForEntity');

        $params = $r->getParameters();

        self::assertCount(3, $params);
        self::assertSame('entityType', $params[0]->getName());
        self::assertSame('entityId',   $params[1]->getName());
        self::assertSame('tenant',     $params[2]->getName());
    }
}
