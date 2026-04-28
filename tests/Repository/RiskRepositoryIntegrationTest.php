<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\RiskRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for RiskRepository QueryBuilder methods.
 *
 * Requires a real database (APP_ENV=test with configured DATABASE_URL).
 * Run with: php bin/phpunit --group integration tests/Repository/RiskRepositoryIntegrationTest.php
 */
#[Group('integration')]
class RiskRepositoryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RiskRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(RiskRepository::class);

        // Wrap each test in a transaction so DB state is always rolled back
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // findHighRisks
    // -------------------------------------------------------------------------

    #[Test]
    public function findHighRisksReturnsOnlyRisksAtOrAboveThreshold(): void
    {
        $tenant = $this->createTestTenant();

        // High: 4 * 4 = 16 >= 12
        $highRisk = $this->createRisk($tenant, 'High Risk', 4, 4, 'mitigate');
        // Below threshold: 2 * 3 = 6 < 12
        $this->createRisk($tenant, 'Low Risk', 2, 3, 'accept');

        $this->em->flush();

        $results = $this->repository->findHighRisks($tenant);

        $ids = array_map(fn(Risk $r): int => $r->getId(), $results);
        $this->assertContains($highRisk->getId(), $ids);
        $this->assertCount(1, $results);
    }

    #[Test]
    public function findHighRisksUsesDefaultThresholdOf12(): void
    {
        $tenant = $this->createTestTenant();

        // Exactly at threshold: 3 * 4 = 12
        $atThreshold = $this->createRisk($tenant, 'At Threshold', 3, 4, 'mitigate');
        // Just below: 3 * 3 = 9
        $this->createRisk($tenant, 'Below Threshold', 3, 3, 'accept');

        $this->em->flush();

        $results = $this->repository->findHighRisks($tenant);

        $ids = array_map(fn(Risk $r): int => $r->getId(), $results);
        $this->assertContains($atThreshold->getId(), $ids);
        $this->assertCount(1, $results);
    }

    #[Test]
    public function findHighRisksRespectsCustomThreshold(): void
    {
        $tenant = $this->createTestTenant();

        $this->createRisk($tenant, 'Risk Score 6', 2, 3, 'mitigate');
        $this->createRisk($tenant, 'Risk Score 2', 1, 2, 'accept');

        $this->em->flush();

        // With threshold=5, score-6 qualifies; score-2 does not
        $results = $this->repository->findHighRisks($tenant, 5);

        $this->assertCount(1, $results);
        $this->assertSame('Risk Score 6', $results[0]->getTitle());
    }

    #[Test]
    public function findHighRisksIsolatesResultsByTenant(): void
    {
        $tenantA = $this->createTestTenant('tenant-a');
        $tenantB = $this->createTestTenant('tenant-b');

        $this->createRisk($tenantA, 'A High', 4, 4, 'mitigate');
        $this->createRisk($tenantB, 'B High', 4, 4, 'mitigate');

        $this->em->flush();

        $resultsA = $this->repository->findHighRisks($tenantA);
        $this->assertCount(1, $resultsA);
        $this->assertSame('A High', $resultsA[0]->getTitle());
    }

    #[Test]
    public function findHighRisksReturnsEmptyArrayWhenNoHighRisksExist(): void
    {
        $tenant = $this->createTestTenant();
        $this->createRisk($tenant, 'Low', 1, 2, 'accept');
        $this->em->flush();

        $results = $this->repository->findHighRisks($tenant);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // findDueForReview
    // NOTE: The repository query references r.nextReviewDate but the Risk entity
    // only has a $reviewDate property. This test documents and exposes that gap.
    // -------------------------------------------------------------------------

    #[Test]
    public function findDueForReviewExposesFieldNameMismatch(): void
    {
        $tenant = $this->createTestTenant();
        $pastDate = new DateTime('-1 day');
        $risk = $this->createRisk($tenant, 'Overdue Review', 2, 2, 'mitigate');
        $risk->setReviewDate($pastDate);
        $this->em->flush();

        // The repository method references r.nextReviewDate which does not exist
        // on the Risk entity (the field is $reviewDate / review_date in the DB).
        // Calling this method is expected to throw a Doctrine mapping exception
        // until the field reference in RiskRepository::findDueForReview() is fixed.
        $this->expectException(\Throwable::class);
        $this->repository->findDueForReview($tenant);
    }

    // -------------------------------------------------------------------------
    // countByTreatmentStrategy
    // -------------------------------------------------------------------------

    #[Test]
    public function countByTreatmentStrategyReturnsCorrectCounts(): void
    {
        $tenant = $this->createTestTenant();

        $this->createRisk($tenant, 'Mitigate 1', 2, 2, 'mitigate');
        $this->createRisk($tenant, 'Mitigate 2', 2, 2, 'mitigate');
        $this->createRisk($tenant, 'Accept 1', 2, 2, 'accept');
        $this->createRisk($tenant, 'Transfer 1', 2, 2, 'transfer');

        $this->em->flush();

        $results = $this->repository->countByTreatmentStrategy($tenant);

        // Index by treatmentStrategy for easy assertion
        $byStrategy = [];
        foreach ($results as $row) {
            $key = $row['treatmentStrategy'] instanceof \App\Enum\TreatmentStrategy
                ? $row['treatmentStrategy']->value
                : (string) $row['treatmentStrategy'];
            $byStrategy[$key] = (int) $row['count'];
        }

        $this->assertSame(2, $byStrategy['mitigate']);
        $this->assertSame(1, $byStrategy['accept']);
        $this->assertSame(1, $byStrategy['transfer']);
        $this->assertArrayNotHasKey('avoid', $byStrategy);
    }

    #[Test]
    public function countByTreatmentStrategyIsolatesByTenant(): void
    {
        $tenantA = $this->createTestTenant('strat-a');
        $tenantB = $this->createTestTenant('strat-b');

        $this->createRisk($tenantA, 'A-mitigate', 2, 2, 'mitigate');
        $this->createRisk($tenantB, 'B-mitigate', 2, 2, 'mitigate');
        $this->createRisk($tenantB, 'B-accept', 2, 2, 'accept');

        $this->em->flush();

        $resultsA = $this->repository->countByTreatmentStrategy($tenantA);
        $this->assertCount(1, $resultsA);
        $tsA = $resultsA[0]['treatmentStrategy'];
        $this->assertSame('mitigate', $tsA instanceof \App\Enum\TreatmentStrategy ? $tsA->value : (string) $tsA);
        $this->assertSame(1, (int) $resultsA[0]['count']);
    }

    #[Test]
    public function countByTreatmentStrategyReturnsEmptyForTenantWithNoRisks(): void
    {
        $tenant = $this->createTestTenant();
        $this->em->flush();

        $results = $this->repository->countByTreatmentStrategy($tenant);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTestTenant(string $suffix = ''): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant ' . $suffix);
        $tenant->setCode('tst_' . uniqid() . $suffix);
        $this->em->persist($tenant);
        return $tenant;
    }

    private function createRisk(
        Tenant $tenant,
        string $title,
        int $probability,
        int $impact,
        string $treatmentStrategy,
    ): Risk {
        $risk = new Risk();
        $risk->setTenant($tenant);
        $risk->setTitle($title);
        $risk->setDescription('Test description for ' . $title);
        $risk->setCategory('security');
        $risk->setProbability($probability);
        $risk->setImpact($impact);
        $risk->setResidualProbability(1);
        $risk->setResidualImpact(1);
        $risk->setTreatmentStrategy(\App\Enum\TreatmentStrategy::from($treatmentStrategy));
        $risk->setStatus(\App\Enum\RiskStatus::Identified);
        $this->em->persist($risk);
        return $risk;
    }
}
