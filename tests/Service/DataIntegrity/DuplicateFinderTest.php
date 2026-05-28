<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Repository\AssetRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use App\Service\DataIntegrity\DuplicateFinder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DuplicateFinderTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $auditRepository;
    private MockObject $assetRepository;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $documentRepository;
    private DuplicateFinder $finder;

    protected function setUp(): void
    {
        $this->entityManager      = $this->createMock(EntityManagerInterface::class);
        $this->auditRepository    = $this->createMock(InternalAuditRepository::class);
        $this->assetRepository    = $this->createMock(AssetRepository::class);
        $this->riskRepository     = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);

        $this->finder = new DuplicateFinder(
            $this->entityManager,
            $this->auditRepository,
            $this->assetRepository,
            $this->riskRepository,
            $this->incidentRepository,
            $this->documentRepository,
        );
    }

    #[Test]
    public function find_duplicate_entities_returns_empty_when_no_data(): void
    {
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->documentRepository->method('findAll')->willReturn([]);

        $result = $this->finder->findDuplicateEntities();

        self::assertSame([], $result);
    }

    #[Test]
    public function find_duplicate_entities_detects_duplicate_audit_numbers(): void
    {
        $tenant = $this->createTenantWithId(1);
        $audit1 = $this->createMockAudit('AUDIT-001', $tenant, 1);
        $audit2 = $this->createMockAudit('AUDIT-001', $tenant, 2); // duplicate number

        $this->auditRepository->method('findAll')->willReturn([$audit1, $audit2]);
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->documentRepository->method('findAll')->willReturn([]);

        $result = $this->finder->findDuplicateEntities();

        self::assertArrayHasKey('audits', $result);
        self::assertCount(1, $result['audits']);
        self::assertSame(2, $result['audits'][0]['count']);
    }

    #[Test]
    public function find_duplicate_entities_does_not_flag_same_name_across_tenants(): void
    {
        $tenant1 = $this->createTenantWithId(1);
        $tenant2 = $this->createTenantWithId(2);

        $asset1 = $this->createMockAsset('Server A', $tenant1, 1);
        $asset2 = $this->createMockAsset('Server A', $tenant2, 2); // same name, different tenant

        $this->auditRepository->method('findAll')->willReturn([]);
        $this->assetRepository->method('findAll')->willReturn([$asset1, $asset2]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->documentRepository->method('findAll')->willReturn([]);

        $result = $this->finder->findDuplicateEntities();

        self::assertArrayNotHasKey('assets', $result);
    }

    #[Test]
    public function merge_duplicates_returns_zero_when_no_duplicates(): void
    {
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->documentRepository->method('findAll')->willReturn([]);

        $deleted = $this->finder->mergeDuplicates('audits');

        self::assertSame(0, $deleted);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function createTenantWithId(int $id): object
    {
        $tenant = $this->createMock(\App\Entity\Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function createMockAudit(string $auditNumber, object $tenant, int $id): object
    {
        $audit = $this->createMock(\App\Entity\InternalAudit::class);
        $audit->method('getTenant')->willReturn($tenant);
        $audit->method('getAuditNumber')->willReturn($auditNumber);
        $audit->method('getId')->willReturn($id);
        return $audit;
    }

    private function createMockAsset(string $name, object $tenant, int $id): object
    {
        $asset = $this->createMock(\App\Entity\Asset::class);
        $asset->method('getTenant')->willReturn($tenant);
        $asset->method('getName')->willReturn($name);
        $asset->method('getId')->willReturn($id);
        return $asset;
    }
}
