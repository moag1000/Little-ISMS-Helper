<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use App\Repository\Notification\NotificationRuleRepository;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\SlaDeadlineWatcher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for SlaDeadlineWatcher::tickAll().
 *
 * Covers:
 *  - Approaching checkpoint fires for a monitor within the look-ahead window
 *  - Second crossing at a smaller checkpoint re-fires (different hours value)
 *  - Satisfied monitor is never returned (repository exclusion)
 *  - Missed deadline fires critical notification and transitions status to 'missed'
 *  - Empty tenant list is a no-op
 */
#[AllowMockObjectsWithoutExpectations]
final class SlaDeadlineWatcherTest extends TestCase
{
    private TenantRepository $tenantRepo;
    private SlaDeadlineMonitorRepository $monitorRepo;
    private NotificationRuleRepository $ruleRepo;
    private NotificationDispatcher $dispatcher;
    private EntityManagerInterface $em;
    private AuditLogger $auditLogger;
    private SlaDeadlineWatcher $watcher;

    protected function setUp(): void
    {
        $this->tenantRepo  = $this->createMock(TenantRepository::class);
        $this->monitorRepo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $this->ruleRepo    = $this->createMock(NotificationRuleRepository::class);
        $this->dispatcher  = $this->createMock(NotificationDispatcher::class);
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->watcher = new SlaDeadlineWatcher(
            tenantRepository:   $this->tenantRepo,
            monitorRepository:  $this->monitorRepo,
            ruleRepository:     $this->ruleRepo,
            dispatcher:         $this->dispatcher,
            entityManager:      $this->em,
            auditLogger:        $this->auditLogger,
            logger:             new NullLogger(),
        );
    }

    #[Test]
    public function emptyTenantListResultsInZeroCounts(): void
    {
        $this->tenantRepo->method('findActive')->willReturn([]);

        $result = $this->watcher->tickAll();

        self::assertSame(0, $result['approached']);
        self::assertSame(0, $result['missed']);
        self::assertEmpty($result['errors']);
    }

    #[Test]
    public function approachingCheckpointFiresWhenWithinWindow(): void
    {
        $tenant  = new Tenant();
        $monitor = $this->makeMonitor(
            deadlineAt:    new DateTimeImmutable('+20 hours'),    // 20h left
            checkpoints:   [48, 24, 12, 4, 1],
            lastNotified:  null,                                   // nothing fired yet
        );

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo
            ->method('findApproachingDeadlines')
            ->willReturn([$monitor]);
        $this->monitorRepo
            ->method('findMissedDeadlines')
            ->willReturn([]);
        $this->ruleRepo
            ->method('findActiveByEventType')
            ->willReturn([]);

        $result = $this->watcher->tickAll();

        // 20h remaining → checkpoint 24h has been crossed; should fire
        self::assertSame(1, $result['approached']);
        self::assertSame(0, $result['missed']);
        // lastNotifiedAtHours should now be set
        self::assertNotNull($monitor->getLastNotifiedAtHours());
    }

    #[Test]
    public function alreadyFiredCheckpointDoesNotRefire(): void
    {
        $tenant  = new Tenant();
        $monitor = $this->makeMonitor(
            deadlineAt:    new DateTimeImmutable('+20 hours'),
            checkpoints:   [48, 24, 12, 4, 1],
            lastNotified:  24,                                     // 24h already fired
        );

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo
            ->method('findApproachingDeadlines')
            ->willReturn([$monitor]);
        $this->monitorRepo
            ->method('findMissedDeadlines')
            ->willReturn([]);
        $this->ruleRepo
            ->method('findActiveByEventType')
            ->willReturn([]);

        $result = $this->watcher->tickAll();

        // 24h already fired; 12h not yet crossed (20h > 12h) — no new fire
        self::assertSame(0, $result['approached']);
    }

    #[Test]
    public function smallerCheckpointFiresAfterLargerAlreadyFired(): void
    {
        $tenant  = new Tenant();
        // 3h remaining — the 4h checkpoint should now fire; 24h was already sent
        $monitor = $this->makeMonitor(
            deadlineAt:    new DateTimeImmutable('+3 hours'),
            checkpoints:   [48, 24, 12, 4, 1],
            lastNotified:  24,
        );

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo
            ->method('findApproachingDeadlines')
            ->willReturn([$monitor]);
        $this->monitorRepo
            ->method('findMissedDeadlines')
            ->willReturn([]);
        $this->ruleRepo
            ->method('findActiveByEventType')
            ->willReturn([]);

        $result = $this->watcher->tickAll();

        self::assertSame(1, $result['approached']);
    }

    #[Test]
    public function missedDeadlineTransitionsStatusAndCounts(): void
    {
        $tenant  = new Tenant();
        $monitor = $this->makeMonitor(
            deadlineAt:   new DateTimeImmutable('-2 hours'),   // 2h overdue
            checkpoints:  [48, 24, 12, 4, 1],
            lastNotified: null,
        );

        self::assertSame(SlaDeadlineStatus::Active, $monitor->getStatus());

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo
            ->method('findApproachingDeadlines')
            ->willReturn([]);
        $this->monitorRepo
            ->method('findMissedDeadlines')
            ->willReturn([$monitor]);
        $this->ruleRepo
            ->method('findActiveByEventType')
            ->willReturn([]);

        $result = $this->watcher->tickAll();

        self::assertSame(0, $result['approached']);
        self::assertSame(1, $result['missed']);
        self::assertSame(SlaDeadlineStatus::Missed, $monitor->getStatus());
    }

    #[Test]
    public function satisfiedMonitorIsNotReturnedByRepository(): void
    {
        // Repository filters satisfied monitors server-side.
        // This test verifies the watcher correctly handles an empty result from findApproachingDeadlines.
        $tenant = new Tenant();

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo
            ->method('findApproachingDeadlines')
            ->willReturn([]); // satisfied not included
        $this->monitorRepo
            ->method('findMissedDeadlines')
            ->willReturn([]);

        $result = $this->watcher->tickAll();

        self::assertSame(0, $result['approached']);
        self::assertSame(0, $result['missed']);
    }

    #[Test]
    public function auditLoggerIsCalledForApproachingCheckpoint(): void
    {
        $tenant  = new Tenant();
        $monitor = $this->makeMonitor(
            deadlineAt:   new DateTimeImmutable('+10 hours'),
            checkpoints:  [12, 4, 1],
            lastNotified: null,
        );

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo->method('findApproachingDeadlines')->willReturn([$monitor]);
        $this->monitorRepo->method('findMissedDeadlines')->willReturn([]);
        $this->ruleRepo->method('findActiveByEventType')->willReturn([]);

        $this->auditLogger
            ->expects(self::atLeastOnce())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SLA_DEADLINE_APPROACHING,
                'SlaDeadlineMonitor',
                self::anything(),
            );

        $this->watcher->tickAll();
    }

    #[Test]
    public function auditLoggerIsCalledForMissedDeadline(): void
    {
        $tenant  = new Tenant();
        $monitor = $this->makeMonitor(
            deadlineAt:   new DateTimeImmutable('-1 hour'),
            checkpoints:  [48, 24, 12, 4, 1],
            lastNotified: null,
        );

        $this->tenantRepo->method('findActive')->willReturn([$tenant]);
        $this->monitorRepo->method('findApproachingDeadlines')->willReturn([]);
        $this->monitorRepo->method('findMissedDeadlines')->willReturn([$monitor]);
        $this->ruleRepo->method('findActiveByEventType')->willReturn([]);

        $this->auditLogger
            ->expects(self::atLeastOnce())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SLA_DEADLINE_MISSED,
                'SlaDeadlineMonitor',
                self::anything(),
            );

        $this->watcher->tickAll();
    }

    // --- Helper --------------------------------------------------------------

    private function makeMonitor(
        DateTimeImmutable $deadlineAt,
        array $checkpoints,
        ?int $lastNotified,
    ): SlaDeadlineMonitor {
        $monitor = new SlaDeadlineMonitor();
        $monitor->setEntityType('DataBreach');
        $monitor->setEntityId(1);
        $monitor->setDeadlineType(SlaDeadlineType::GdprNotification72h);
        $monitor->setTriggeredAt(new DateTimeImmutable('-52 hours'));
        $monitor->setDeadlineAt($deadlineAt);
        $monitor->setNotifyAtCheckpoints($checkpoints);
        $monitor->setLastNotifiedAtHours($lastNotified);
        $monitor->setStatus(SlaDeadlineStatus::Active);

        return $monitor;
    }
}
