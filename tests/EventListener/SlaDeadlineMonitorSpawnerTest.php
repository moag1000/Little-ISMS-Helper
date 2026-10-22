<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\SlaDeadlineType;
use App\EventListener\SlaDeadlineMonitorSpawner;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Service\Notification\SlaDeadlineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SLA monitors (GDPR 72h, NIS2 24h/72h, ISO 10.1 30d) must be started by a
 * live path.
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
    public function reportableHighIncidentStartsNis2EarlyWarning(): void
    {
        $incident = $this->entity(new Incident(), 9);
        $incident->setSeverity(IncidentSeverity::High);
        $incident->setNotificationRequired(true);

        $spawner = $this->spawner();
        $spawner->postPersist(new PostPersistEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame(SlaDeadlineType::Nis2EarlyWarning24h, $this->persisted[0]->getDeadlineType());
    }

    #[Test]
    public function incidentFlaggedLaterStartsClockOnUpdate(): void
    {
        $incident = $this->entity(new Incident(), 10);
        $spawner = $this->spawner();

        $spawner->postPersist(new PostPersistEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));
        self::assertSame([], $this->persisted, 'non-reportable incident has no regulatory clock');

        $incident->setNis2Category('security');
        $spawner->postUpdate(new PostUpdateEventArgs($incident, $this->em));
        $spawner->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame(SlaDeadlineType::Nis2Notification72h, $this->persisted[0]->getDeadlineType());
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
        $spawner = $this->spawner(existing: new SlaDeadlineMonitor());
        $spawner->postPersist(new PostPersistEventArgs($this->entity(new DataBreach(), 5), $this->em));
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
        $spawner = new SlaDeadlineMonitorSpawner(new SlaDeadlineFactory($em), $repository, $logger);

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
        $spawner = new SlaDeadlineMonitorSpawner(new SlaDeadlineFactory($em), $this->createStub(SlaDeadlineMonitorRepository::class), $logger);

        $spawner->postPersist(new PostPersistEventArgs($this->entity(new DataBreach(), 5), $em));
        $spawner->postFlush(new PostFlushEventArgs($em));

        self::assertSame(['info'], $logger->levels);
    }

    private function spawner(?SlaDeadlineMonitor $existing = null): SlaDeadlineMonitorSpawner
    {
        $repository = $this->createStub(SlaDeadlineMonitorRepository::class);
        $repository->method('findForEntity')->willReturn($existing);

        return new SlaDeadlineMonitorSpawner(new SlaDeadlineFactory($this->em), $repository, new NullLogger());
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
