<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Repository\IncidentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for IncidentRepository QueryBuilder methods.
 *
 * Requires a real database (APP_ENV=test with configured DATABASE_URL).
 * Run with: php bin/phpunit --group integration tests/Repository/IncidentRepositoryIntegrationTest.php
 *
 * IncidentStatus enum values: Reported, InInvestigation, InResolution, Resolved, Closed.
 * findOpenIncidents() returns Reported / InInvestigation / InResolution.
 */
#[Group('integration')]
class IncidentRepositoryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IncidentRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(IncidentRepository::class);

        // Wrap each test in a transaction so DB state is always rolled back
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // findOpenIncidents
    // -------------------------------------------------------------------------

    #[Test]
    public function findOpenIncidentsReturnsIncidentsWithOpenStatus(): void
    {
        $tenant = $this->createTestTenant();

        // Insert incidents with the statuses the repository actually searches for.
        // We bypass validation by using DBAL directly so we can confirm the query
        // behaviour independent of the entity constraint.
        $openIncident = $this->createIncidentRaw($tenant, 'Open Incident', 'high', 'network', 'open');
        $investigatingIncident = $this->createIncidentRaw($tenant, 'Investigating Incident', 'medium', 'malware', 'investigating');
        $inProgressIncident = $this->createIncidentRaw($tenant, 'In Progress Incident', 'low', 'phishing', 'in_progress');

        // These statuses should NOT appear in open incidents
        $this->createIncidentRaw($tenant, 'Resolved Incident', 'high', 'network', 'resolved');
        $this->createIncidentRaw($tenant, 'Closed Incident', 'medium', 'malware', 'closed');

        $this->em->flush();

        $results = $this->repository->findOpenIncidents($tenant);

        $this->assertCount(3, $results);
        $ids = array_map(fn(Incident $i): int => $i->getId(), $results);
        $this->assertContains($openIncident->getId(), $ids);
        $this->assertContains($investigatingIncident->getId(), $ids);
        $this->assertContains($inProgressIncident->getId(), $ids);
    }

    #[Test]
    public function findOpenIncidentsReturnsEmptyWhenAllClosed(): void
    {
        $tenant = $this->createTestTenant();
        $this->createIncidentRaw($tenant, 'Resolved', 'low', 'network', 'resolved');
        $this->createIncidentRaw($tenant, 'Closed', 'low', 'malware', 'closed');
        $this->em->flush();

        $results = $this->repository->findOpenIncidents($tenant);

        $this->assertSame([], $results);
    }

    #[Test]
    public function findOpenIncidentsIsolatesByTenant(): void
    {
        $tenantA = $this->createTestTenant('open-a');
        $tenantB = $this->createTestTenant('open-b');

        $incidentA = $this->createIncidentRaw($tenantA, 'A Open', 'high', 'network', 'open');
        $this->createIncidentRaw($tenantB, 'B Open', 'high', 'network', 'open');

        $this->em->flush();

        $results = $this->repository->findOpenIncidents($tenantA);
        $this->assertCount(1, $results);
        $this->assertSame($incidentA->getId(), $results[0]->getId());
    }

    // -------------------------------------------------------------------------
    // countBySeverity
    // -------------------------------------------------------------------------

    #[Test]
    public function countBySeverityReturnsCorrectGroupedCounts(): void
    {
        $tenant = $this->createTestTenant();

        $this->createIncident($tenant, 'C1', 'critical', 'network', 'reported');
        $this->createIncident($tenant, 'C2', 'critical', 'malware', 'reported');
        $this->createIncident($tenant, 'H1', 'high', 'network', 'reported');
        $this->createIncident($tenant, 'L1', 'low', 'phishing', 'reported');

        $this->em->flush();

        $results = $this->repository->countBySeverity($tenant);

        $bySeverity = [];
        foreach ($results as $row) {
            $key = $row['severity'] instanceof \App\Enum\IncidentSeverity ? $row['severity']->value : (string) $row['severity'];
            $bySeverity[$key] = (int) $row['count'];
        }

        $this->assertSame(2, $bySeverity['critical']);
        $this->assertSame(1, $bySeverity['high']);
        $this->assertSame(1, $bySeverity['low']);
        $this->assertArrayNotHasKey('medium', $bySeverity);
    }

    #[Test]
    public function countBySeverityIsolatesByTenant(): void
    {
        $tenantA = $this->createTestTenant('sev-a');
        $tenantB = $this->createTestTenant('sev-b');

        $this->createIncident($tenantA, 'A-critical', 'critical', 'network', 'reported');
        $this->createIncident($tenantB, 'B-critical', 'critical', 'network', 'reported');
        $this->createIncident($tenantB, 'B-high', 'high', 'network', 'reported');

        $this->em->flush();

        $resultsA = $this->repository->countBySeverity($tenantA);
        $this->assertCount(1, $resultsA);
        $sevA = $resultsA[0]['severity'];
        $this->assertSame('critical', $sevA instanceof \App\Enum\IncidentSeverity ? $sevA->value : (string) $sevA);
        $this->assertSame(1, (int) $resultsA[0]['count']);
    }

    #[Test]
    public function countBySeverityReturnsEmptyForTenantWithNoIncidents(): void
    {
        $tenant = $this->createTestTenant();
        $this->em->flush();

        $results = $this->repository->countBySeverity($tenant);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // countByCategory
    // -------------------------------------------------------------------------

    #[Test]
    public function countByCategoryReturnsCorrectGroupedCounts(): void
    {
        $tenant = $this->createTestTenant();

        $this->createIncident($tenant, 'N1', 'high', 'network', 'reported');
        $this->createIncident($tenant, 'N2', 'medium', 'network', 'reported');
        $this->createIncident($tenant, 'M1', 'low', 'malware', 'reported');

        $this->em->flush();

        $results = $this->repository->countByCategory($tenant);

        $byCategory = [];
        foreach ($results as $row) {
            $byCategory[$row['category']] = (int) $row['count'];
        }

        $this->assertSame(2, $byCategory['network']);
        $this->assertSame(1, $byCategory['malware']);
    }

    #[Test]
    public function countByCategoryIsolatesByTenant(): void
    {
        $tenantA = $this->createTestTenant('cat-a');
        $tenantB = $this->createTestTenant('cat-b');

        $this->createIncident($tenantA, 'A-phishing', 'low', 'phishing', 'reported');
        $this->createIncident($tenantB, 'B-phishing', 'low', 'phishing', 'reported');

        $this->em->flush();

        $resultsA = $this->repository->countByCategory($tenantA);
        $this->assertCount(1, $resultsA);
        $this->assertSame('phishing', $resultsA[0]['category']);
        $this->assertSame(1, (int) $resultsA[0]['count']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTestTenant(string $suffix = ''): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant ' . $suffix);
        $tenant->setCode('inc_' . uniqid() . $suffix);
        $this->em->persist($tenant);
        return $tenant;
    }

    /**
     * Create an Incident with validated status values (reported, in_investigation,
     * in_resolution, resolved, closed) — passes entity constraint validation.
     */
    private function createIncident(
        Tenant $tenant,
        string $title,
        string $severity,
        string $category,
        string $status,
    ): Incident {
        $incident = new Incident();
        $incident->setTenant($tenant);
        $incident->setIncidentNumber('INC-' . uniqid());
        $incident->setTitle($title);
        $incident->setDescription('Test description for ' . $title);
        $incident->setCategory($category);
        $incident->setSeverity(\App\Enum\IncidentSeverity::from($severity));
        $statusValue = $status === 'investigating' ? 'in_investigation' : ($status === 'new' ? 'reported' : ($status === 'open' ? 'reported' : $status));
        $incident->setStatus(\App\Enum\IncidentStatus::from($statusValue));
        $incident->setReportedBy('Integration Test');
        $incident->setDetectedAt(new DateTimeImmutable());
        $incident->setDataBreachOccurred(false);
        $incident->setNotificationRequired(false);
        $this->em->persist($incident);
        return $incident;
    }

    /**
     * Create an Incident, mapping legacy status names to current enum values.
     * findOpenIncidents() now matches IncidentStatus::Reported, InInvestigation,
     * InResolution. Tests still pass legacy names for readability.
     */
    private function createIncidentRaw(
        Tenant $tenant,
        string $title,
        string $severity,
        string $category,
        string $status,
    ): Incident {
        $statusMap = [
            'open' => \App\Enum\IncidentStatus::Reported,
            'investigating' => \App\Enum\IncidentStatus::InInvestigation,
            'in_progress' => \App\Enum\IncidentStatus::InResolution,
            'reported' => \App\Enum\IncidentStatus::Reported,
            'in_investigation' => \App\Enum\IncidentStatus::InInvestigation,
            'in_resolution' => \App\Enum\IncidentStatus::InResolution,
            'resolved' => \App\Enum\IncidentStatus::Resolved,
            'closed' => \App\Enum\IncidentStatus::Closed,
        ];
        $statusEnum = $statusMap[$status] ?? \App\Enum\IncidentStatus::Reported;

        $incident = new Incident();
        $incident->setTenant($tenant);
        $incident->setIncidentNumber('INC-' . uniqid());
        $incident->setTitle($title);
        $incident->setDescription('Test description for ' . $title);
        $incident->setCategory($category);
        $incident->setSeverity(\App\Enum\IncidentSeverity::from($severity));
        $incident->setStatus($statusEnum);
        $incident->setReportedBy('Integration Test');
        $incident->setDetectedAt(new DateTimeImmutable());
        $incident->setDataBreachOccurred(false);
        $incident->setNotificationRequired(false);
        $this->em->persist($incident);
        return $incident;
    }
}
