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
        $historic = $this->incident(notificationRequired: true, detectedAt: $now->modify('-5 days'));
        $irrelevant = $this->incident(notificationRequired: false, detectedAt: $now->modify('-1 hour'));

        // The spawner started the clocks on flush; simulate the failed spawns.
        self::assertSame(1, $this->monitorsFor($failed), 'the spawner runs on flush');
        $this->conn->executeStatement(
            "DELETE FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id IN (?, ?)",
            [$failed, $historic],
        );

        $this->retry->spawnMissing($now);
        $this->em->clear();

        self::assertSame(1, $this->monitorsFor($failed), 'the failed spawn is retried');
        self::assertSame(
            SlaDeadlineType::Nis2EarlyWarning24h->value,
            $this->conn->fetchOne("SELECT deadline_type FROM sla_deadline_monitor WHERE entity_type = 'Incident' AND entity_id = ?", [$failed]),
        );
        self::assertSame(0, $this->monitorsFor($historic), 'no back-fill of a deadline that has already passed');
        self::assertSame(0, $this->monitorsFor($irrelevant));

        self::assertSame(0, $this->retry->spawnMissing($now), 'a second run finds nothing to do');
        self::assertSame(1, $this->monitorsFor($failed), 'no duplicate monitor');
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
