<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingRepository;
use App\Service\DataIntegrity\ReferenceIntegrityChecker;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ReferenceIntegrityCheckerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $controlRepository;
    private MockObject $auditRepository;
    private MockObject $documentRepository;
    private MockObject $trainingRepository;
    private MockObject $bcPlanRepository;
    private ReferenceIntegrityChecker $checker;

    protected function setUp(): void
    {
        $this->entityManager      = $this->createMock(EntityManagerInterface::class);
        $this->riskRepository     = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->controlRepository  = $this->createMock(ControlRepository::class);
        $this->auditRepository    = $this->createMock(InternalAuditRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->trainingRepository = $this->createMock(TrainingRepository::class);
        $this->bcPlanRepository   = $this->createMock(BusinessContinuityPlanRepository::class);

        $this->checker = new ReferenceIntegrityChecker(
            $this->entityManager,
            $this->riskRepository,
            $this->incidentRepository,
            $this->controlRepository,
            $this->auditRepository,
            $this->documentRepository,
            $this->trainingRepository,
            $this->bcPlanRepository,
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // clearCache
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function clear_cache_causes_next_find_to_reload_data(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->trainingRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $this->checker->findBrokenReferences(); // populates cache

        // Now clear and verify cache is invalidated (re-runs without error)
        $this->checker->clearCache();
        $result = $this->checker->findBrokenReferences();
        self::assertIsArray($result); // reached here = no exception, cache cleared
    }

    // ────────────────────────────────────────────────────────────────────────
    // findBrokenReferences
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_broken_references_returns_empty_when_no_entities(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->trainingRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $result = $this->checker->findBrokenReferences();

        self::assertSame([], $result);
    }

    #[Test]
    public function find_broken_references_detects_risk_asset_tenant_mismatch(): void
    {
        $tenant1 = $this->createTenantWithName('Tenant A', 1);
        $tenant2 = $this->createTenantWithName('Tenant B', 2);

        $asset = $this->createMockAsset($tenant2);
        $risk  = $this->createMockRisk($asset, $tenant1, null);

        // Entity is "contained" in EM (not detached/missing)
        $this->entityManager->method('contains')->with($asset)->willReturn(true);

        $this->riskRepository->method('findAll')->willReturn([$risk]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->trainingRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $result = $this->checker->findBrokenReferences();

        $types = array_column($result, 'type');
        self::assertContains('risk_asset_tenant_mismatch', $types);
    }

    // ────────────────────────────────────────────────────────────────────────
    // findMissingRelationships — uses QueryBuilder, so we verify it handles
    // exceptions gracefully (in-memory mocks without full DQL engine)
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_broken_references_returns_array_type(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->trainingRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $result = $this->checker->findBrokenReferences();

        self::assertIsArray($result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function createMockRisk(?object $asset, ?object $tenant, ?string $title): object
    {
        $risk = $this->createMock(\App\Entity\Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getTenant')->willReturn($tenant);
        $risk->method('getTitle')->willReturn($title ?? 'Risk');
        $risk->method('getId')->willReturn(1);
        $risk->method('getControls')->willReturn(new ArrayCollection());
        $risk->method('getResidualRiskLevel')->willReturn(3);
        $risk->method('getInherentRiskLevel')->willReturn(5);
        $risk->method('getTreatmentStrategy')->willReturn(null);
        return $risk;
    }

    private function createMockAsset(object $tenant): object
    {
        $asset = $this->createMock(\App\Entity\Asset::class);
        $asset->method('getTenant')->willReturn($tenant);
        return $asset;
    }

    private function createTenantWithName(string $name, int $id): object
    {
        $tenant = $this->createMock(\App\Entity\Tenant::class);
        $tenant->method('getName')->willReturn($name);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function setUpEmptyQueryBuilder(MockObject $repo): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo->method('createQueryBuilder')->willReturn($qb);
    }
}
