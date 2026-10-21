<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Enum\SlaDeadlineType;
use App\Service\Notification\SlaDeadlineMonitorRetry;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A failed SLA spawn (SlaDeadlineMonitorSpawner logs and moves on) is retried
 * by the next `app:process-timed-workflows` run — idempotently, and only for
 * deadlines still ahead (#1059).
 */
final class SlaDeadlineMonitorRetryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private SlaDeadlineMonitorRetry $retry;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->retry = self::getContainer()->get(SlaDeadlineMonitorRetry::class);

        $uid = uniqid('', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('SLA retry ' . $uid);
        $this->tenant->setCode('slar_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $this->conn->executeStatement('DELETE FROM sla_deadline_monitor WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM incident WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function failedSpawnIsRetriedOnceAndHistoricRecordsAreLeftAlone(): void
    {
        $now = new DateTimeImmutable();
        $failed = $this->incident(notificationRequired: true, detectedAt: $now->modify('-1 hour'));
        $recentlyMissed = $this->incident(notificationRequired: true, detectedAt: $now->modify('-30 hours'));
        $historic = $this->incident(notificationRequired: true, detectedAt: $now->modify('-7 days'));
        $irrelevant = $this->incident(notificationRequired: false, detectedAt: $now->modify('-1 hour'));

        // The spawner started the clocks on flush; simulate the failed spawns.
        self::assertSame(1, $this->monitorsFor($failed), 'the spawner runs on flush');
        $this->conn->executeStatement(
            "DELETE FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id IN (?, ?, ?)",
            [$failed, $recentlyMissed, $historic],
        );

        $this->retry->spawnMissing($now);
        $this->em->clear();

        self::assertSame(1, $this->monitorsFor($failed), 'the failed spawn is retried');
        self::assertSame(
            SlaDeadlineType::Nis2EarlyWarning24h->value,
            $this->conn->fetchOne("SELECT deadline_type FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id = ?", [$failed]),
        );
        self::assertSame(1, $this->monitorsFor($recentlyMissed), 'a deadline missed within the grace window still gets its clock');
        self::assertSame(0, $this->monitorsFor($historic), 'no back-fill of long-past deadlines');
        self::assertSame(0, $this->monitorsFor($irrelevant));

        self::assertSame(0, $this->retry->spawnMissing($now), 'a second run finds nothing to do');
        self::assertSame(1, $this->monitorsFor($failed), 'no duplicate monitor');

        // The watcher then fires the "missed" alert once for the recently missed clock.
        self::getContainer()->get(\App\Service\Notification\SlaDeadlineWatcher::class)->tickAll();
        self::assertSame(
            'missed',
            $this->conn->fetchOne("SELECT status FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id = ?", [$recentlyMissed]),
        );
        self::assertSame(
            'active',
            $this->conn->fetchOne("SELECT status FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id = ?", [$failed]),
        );
    }

    /**
     * #1059: the unique key (entity_type, entity_id, deadline_type) rejects a
     * second clock — e.g. the web spawner and the cron retry racing.
     */
    #[Test]
    public function databaseRejectsADuplicateClock(): void
    {
        $incident = $this->incident(notificationRequired: true, detectedAt: new DateTimeImmutable('-1 hour'));
        self::assertSame(1, $this->monitorsFor($incident));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->conn->executeStatement(
            'INSERT INTO sla_deadline_monitor (tenant_id, entity_type, entity_id, deadline_type, triggered_at, deadline_at, notify_at_checkpoints, status)
             SELECT tenant_id, entity_type, entity_id, deadline_type, triggered_at, deadline_at, notify_at_checkpoints, status
             FROM sla_deadline_monitor WHERE entity_type = ? AND entity_id = ?',
            ['Incident', $incident],
        );
    }

    /**
     * #1059 re-review m-N1: a record whose monitor the database really
     * rejects (closing the EntityManager) is skipped; the records after it
     * still get their clocks in the same run.
     */
    #[Test]
    public function oneUnstorableRecordDoesNotBlockTheOnesAfterIt(): void
    {
        $now = new DateTimeImmutable();
        $bad = $this->incident(notificationRequired: true, detectedAt: $now->modify('-1 hour'));
        $after = $this->incident(notificationRequired: true, detectedAt: $now->modify('-1 hour'));
        self::assertGreaterThan($bad, $after, 'candidates are processed in id order');
        $this->conn->executeStatement(
            "DELETE FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id IN (?, ?)",
            [$bad, $after],
        );

        // Make the database reject exactly the bad record's monitor:
        // entity_type is VARCHAR(80).
        $this->em->getEventManager()->addEventListener(\Doctrine\ORM\Events::onFlush, new class ($bad) {
            public function __construct(private readonly int $badId)
            {
            }

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                $uow = $args->getObjectManager()->getUnitOfWork();
                foreach ($uow->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof \App\Entity\Notification\SlaDeadlineMonitor && $entity->getEntityId() === $this->badId) {
                        $entity->setEntityType(str_repeat('X', 120));
                        $uow->recomputeSingleEntityChangeSet(
                            $args->getObjectManager()->getClassMetadata($entity::class),
                            $entity,
                        );
                    }
                }
            }
        });

        $this->retry->spawnMissing($now);

        self::assertTrue($this->em->isOpen(), 'the closed manager was recovered');
        self::assertSame(0, $this->monitorsFor($bad));
        self::assertSame(1, $this->monitorsFor($after), 'the record after the bad one still got its clock');
    }

    private function incident(bool $notificationRequired, DateTimeImmutable $detectedAt): int
    {
        $incident = new Incident();
        $incident->setTenant($this->tenant);
        $incident->setIncidentNumber('INC-SLA-' . uniqid());
        $incident->setTitle('SLA retry probe');
        $incident->setDescription('SLA retry probe');
        $incident->setCategory('security');
        $incident->setSeverity(IncidentSeverity::High);
        $incident->setStatus(IncidentStatus::from('reported'));
        $incident->setReportedBy('SLA retry test');
        $incident->setDetectedAt($detectedAt);
        $incident->setDataBreachOccurred(false);
        $incident->setNotificationRequired($notificationRequired);
        $this->em->persist($incident);
        $this->em->flush();

        return (int) $incident->getId();
    }

    private function monitorsFor(int $incidentId): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id = ?",
            [$incidentId],
        );
    }
}
