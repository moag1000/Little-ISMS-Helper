<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use App\EventListener\SlaDeadlineMonitorSpawner;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Service\Incident\ReportingPlanner;
use App\Service\ModuleConfigurationService;
use App\Service\Notification\SlaDeadlineFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SLA monitors (GDPR 72h, NIS-2 24h/72h/final, DORA, ISO 10.1 30d) are
 * started and kept in line by a live path: one monitor per statutory report.
 */
final class SlaDeadlineMonitorSpawnerTest extends TestCase
{
    /** @var list<SlaDeadlineMonitor> */
    private array $persisted = [];
    private int $flushes = 0;
    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });
        $this->em = $em;
    }

    #[Test]
    public function dataBreachStartsGdpr72hClock(): void
    {
        $spawner = $this->spawner();
        $spawner->postPersist(new PostPersistEventArgs($this->entity(new DataBreach(), 5), $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertCount(1, $this->persisted);
        self::assertSame(SlaDeadlineType::GdprNotification72h, $this->persisted[0]->getDeadlineType());
        self::assertSame('DataBreach', $this->persisted[0]->getEntityType());
        self::assertSame(5, $this->persisted[0]->getEntityId());
        self::assertSame(1, $this->flushes);
    }

    #[Test]
    public function reportableIncidentStartsOneClockPerNis2Report(): void
    {
        $incident = $this->incident(9);
        $incident->setSeverity(IncidentSeverity::High);

        $spawner = $this->spawner();
        $spawner->postPersist(new PostPersistEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame(
            [SlaDeadlineType::Nis2EarlyWarning24h, SlaDeadlineType::Nis2Notification72h, SlaDeadlineType::Nis2FinalReport1mo],
            array_map(static fn (SlaDeadlineMonitor $m): SlaDeadlineType => $m->getDeadlineType(), $this->persisted),
        );
    }

    #[Test]
    public function incidentFlaggedLaterStartsClockOnUpdate(): void
    {
        $incident = $this->incident(10);
        $spawner = $this->spawner();

        $spawner->postPersist(new PostPersistEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));
        self::assertSame([], $this->persisted, 'non-reportable incident has no regulatory clock');

        $incident->setNotificationRequired(true);
        $spawner->postUpdate(new PostUpdateEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertCount(3, $this->persisted);
    }

    #[Test]
    public function recordedReportSatisfiesItsClock(): void
    {
        $incident = $this->incident(11);
        $incident->setNotificationRequired(true);
        $incident->setEarlyWarningReportedAt(new DateTimeImmutable('2026-11-01 12:00'));
        $running = (new SlaDeadlineMonitor())->setDeadlineType(SlaDeadlineType::Nis2EarlyWarning24h);

        $spawner = $this->spawner([$running]);
        $spawner->postUpdate(new PostUpdateEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame(SlaDeadlineStatus::Satisfied, $running->getStatus());
        self::assertEquals(new DateTimeImmutable('2026-11-01 12:00'), $running->getSatisfiedAt());
        self::assertCount(2, $this->persisted, 'notification and final report get their clocks');
    }

    #[Test]
    public function assessedNotReportableDismissesRunningClocks(): void
    {
        $incident = $this->incident(12);
        $incident->setSeverity(IncidentSeverity::Critical);
        $incident->setNotificationRequired(false);
        $running = (new SlaDeadlineMonitor())->setDeadlineType(SlaDeadlineType::Nis2EarlyWarning24h);

        $spawner = $this->spawner([$running]);
        $spawner->postUpdate(new PostUpdateEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame(SlaDeadlineStatus::Dismissed, $running->getStatus());
        self::assertSame([], $this->persisted);
        self::assertSame(1, $this->flushes);
    }

    #[Test]
    public function nonconformityStartsCorrectiveActionClockButObservationDoesNot(): void
    {
        $nc = $this->entity(new AuditFinding(), 1);
        $nc->setType(AuditFinding::TYPE_MAJOR_NC);
        $observation = $this->entity(new AuditFinding(), 2);
        $observation->setType(AuditFinding::TYPE_OBSERVATION);

        $spawner = $this->spawner();
        $spawner->postPersist(new PostPersistEventArgs($nc, $this->em));
        $spawner->postPersist(new PostPersistEventArgs($observation, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertCount(1, $this->persisted);
        self::assertSame(SlaDeadlineType::IsoCorrectiveAction30d, $this->persisted[0]->getDeadlineType());
        self::assertSame(1, $this->persisted[0]->getEntityId());
    }

    #[Test]
    public function existingMonitorIsNotDuplicated(): void
    {
        $breach = $this->entity(new DataBreach(), 5);
        $breach->setDetectedAt(new DateTimeImmutable('2020-01-01 08:00'));
        $existing = (new SlaDeadlineMonitor())->setDeadlineType(SlaDeadlineType::GdprNotification72h);
        $spawner = $this->spawner([$existing]);
        $spawner->postPersist(new PostPersistEventArgs($breach, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));
        $this->flushes = 0;

        // A second save without changes writes nothing.
        $spawner->postUpdate(new PostUpdateEventArgs($breach, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame([], $this->persisted);
        self::assertSame(0, $this->flushes);
    }

    /**
     * The triggering change is committed before postFlush: a failing follow-up
     * flush must not fail the caller's request — the cron retry
     * (SlaDeadlineMonitorRetry) starts the clock instead.
     */
    #[Test]
    public function failingMonitorFlushIsLoggedNotThrown(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('deadlock'));
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
        };
        $repository = $this->createStub(SlaDeadlineMonitorRepository::class);
        $spawner = new SlaDeadlineMonitorSpawner($this->factory(), $repository, $logger);

        $spawner->postPersist(new PostPersistEventArgs($this->entity(new DataBreach(), 5), $em));
        $spawner->postFlush(new PostFlushEventArgs($em));

        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('next cron run retries', $logger->errors[0]);
    }

    /**
     * A concurrent spawn rejected by the unique key is "already started",
     * not an error.
     */
    #[Test]
    public function uniqueViolationIsTreatedAsAlreadyStarted(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(
            (new \ReflectionClass(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class))->newInstanceWithoutConstructor(),
        );
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };
        $spawner = new SlaDeadlineMonitorSpawner($this->factory(), $this->createStub(SlaDeadlineMonitorRepository::class), $logger);

        $spawner->postPersist(new PostPersistEventArgs($this->entity(new DataBreach(), 5), $em));
        $spawner->postFlush(new PostFlushEventArgs($em));

        self::assertSame(['info'], $logger->levels);
    }

    /** @param list<SlaDeadlineMonitor> $existing */
    private function spawner(array $existing = []): SlaDeadlineMonitorSpawner
    {
        $repository = $this->createStub(SlaDeadlineMonitorRepository::class);
        $repository->method('findAllForEntity')->willReturn($existing);

        return new SlaDeadlineMonitorSpawner($this->factory(), $repository, new NullLogger());
    }

    private function factory(): SlaDeadlineFactory
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturn(true);

        return new SlaDeadlineFactory(new ReportingPlanner(static fn (): ModuleConfigurationService => $modules));
    }

    private function incident(int $id): Incident
    {
        $incident = $this->entity(new Incident(), $id);
        $incident->setDetectedAt(new DateTimeImmutable('2026-11-01 08:00'));

        return $incident;
    }

    /**
     * @template T of DataBreach|Incident|AuditFinding
     * @param T $entity
     * @return T
     */
    private function entity(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
        $entity->setTenant($this->tenant);

        return $entity;
    }
}
