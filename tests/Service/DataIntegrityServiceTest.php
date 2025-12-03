<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\TenantRepository;
use App\Repository\TrainingRepository;
use App\Service\DataIntegrityService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataIntegrityServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $assetRepository;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $tenantRepository;
    private MockObject $controlRepository;
    private MockObject $auditRepository;
    private MockObject $documentRepository;
    private MockObject $trainingRepository;
    private MockObject $businessProcessRepository;
    private MockObject $bcPlanRepository;
    private MockObject $dataBreachRepository;
    private MockObject $processingActivityRepository;
    private MockObject $supplierRepository;
    private MockObject $locationRepository;
    private MockObject $personRepository;
    private DataIntegrityService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->auditRepository = $this->createMock(InternalAuditRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->trainingRepository = $this->createMock(TrainingRepository::class);
        $this->businessProcessRepository = $this->createMock(BusinessProcessRepository::class);
        $this->bcPlanRepository = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->dataBreachRepository = $this->createMock(DataBreachRepository::class);
        $this->processingActivityRepository = $this->createMock(ProcessingActivityRepository::class);
        $this->supplierRepository = $this->createMock(SupplierRepository::class);
        $this->locationRepository = $this->createMock(LocationRepository::class);
        $this->personRepository = $this->createMock(PersonRepository::class);

        $this->service = new DataIntegrityService(
            $this->entityManager,
            $this->assetRepository,
            $this->riskRepository,
            $this->incidentRepository,
            $this->tenantRepository,
            $this->controlRepository,
            $this->auditRepository,
            $this->documentRepository,
            $this->trainingRepository,
            $this->businessProcessRepository,
            $this->bcPlanRepository,
            $this->dataBreachRepository,
            $this->processingActivityRepository,
            $this->supplierRepository,
            $this->locationRepository,
            $this->personRepository
        );
    }

    // ========== runFullIntegrityCheck TESTS ==========

    public function testRunFullIntegrityCheckReturnsAllCategories(): void
    {
        $this->setupEmptyRepositoryMocks();

        $result = $this->service->runFullIntegrityCheck();

        $this->assertArrayHasKey('orphaned_entities', $result);
        $this->assertArrayHasKey('duplicates', $result);
        $this->assertArrayHasKey('broken_references', $result);
        $this->assertArrayHasKey('missing_relationships', $result);
        $this->assertArrayHasKey('inconsistent_data', $result);
        $this->assertArrayHasKey('entity_counts', $result);
    }

    // ========== findAllOrphanedEntities TESTS ==========

    public function testFindAllOrphanedEntitiesReturnsEmptyArraysWhenNoOrphans(): void
    {
        $this->setupEmptyOrphanMocks();

        $result = $this->service->findAllOrphanedEntities();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('assets', $result);
        $this->assertArrayHasKey('risks', $result);
        $this->assertArrayHasKey('incidents', $result);
        $this->assertEmpty($result['assets']);
        $this->assertEmpty($result['risks']);
        $this->assertEmpty($result['incidents']);
    }

    public function testFindAllOrphanedEntitiesReturnsOrphanedAssets(): void
    {
        $orphanedAsset = $this->createMock(Asset::class);

        $this->setupOrphanMocksWithAssets([$orphanedAsset]);

        $result = $this->service->findAllOrphanedEntities();

        $this->assertCount(1, $result['assets']);
        $this->assertSame($orphanedAsset, $result['assets'][0]);
    }

    // ========== findDuplicateEntities TESTS ==========

    public function testFindDuplicateEntitiesReturnsEmptyWhenNoDuplicates(): void
    {
        $tenant = $this->createTenantMock(1, 'Test Tenant');

        $audit = $this->createAuditMock($tenant, 'AUDIT-001');
        $this->auditRepository->method('findAll')->willReturn([$audit]);

        $asset = $this->createAssetMock($tenant, 'Asset 1');
        $this->assetRepository->method('findAll')->willReturn([$asset]);

        $risk = $this->createRiskMock($tenant, 'Risk 1');
        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->service->findDuplicateEntities();

        $this->assertEmpty($result);
    }

    public function testFindDuplicateEntitiesDetectsDuplicateAuditNumbers(): void
    {
        $tenant = $this->createTenantMock(1, 'Test Tenant');

        $audit1 = $this->createAuditMock($tenant, 'AUDIT-001');
        $audit2 = $this->createAuditMock($tenant, 'AUDIT-001');  // Duplicate
        $this->auditRepository->method('findAll')->willReturn([$audit1, $audit2]);

        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->findDuplicateEntities();

        $this->assertArrayHasKey('audits', $result);
        $this->assertCount(1, $result['audits']);
        $this->assertSame(2, $result['audits'][0]['count']);
        $this->assertSame('auditNumber', $result['audits'][0]['field']);
    }

    public function testFindDuplicateEntitiesDetectsDuplicateAssetNames(): void
    {
        $tenant = $this->createTenantMock(1, 'Test Tenant');

        $asset1 = $this->createAssetMock($tenant, 'Server-DB');
        $asset2 = $this->createAssetMock($tenant, 'Server-DB');  // Duplicate (case-insensitive)
        $this->assetRepository->method('findAll')->willReturn([$asset1, $asset2]);

        $this->auditRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->findDuplicateEntities();

        $this->assertArrayHasKey('assets', $result);
        $this->assertCount(1, $result['assets']);
        $this->assertSame('name', $result['assets'][0]['field']);
    }

    public function testFindDuplicateEntitiesIgnoresEntitiesWithoutTenant(): void
    {
        // Entities without tenant should not be counted as duplicates
        $audit1 = $this->createAuditMock(null, 'AUDIT-001');
        $audit2 = $this->createAuditMock(null, 'AUDIT-001');
        $this->auditRepository->method('findAll')->willReturn([$audit1, $audit2]);

        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->findDuplicateEntities();

        $this->assertEmpty($result);
    }

    public function testFindDuplicateEntitiesDistinguishesBetweenTenants(): void
    {
        $tenant1 = $this->createTenantMock(1, 'Tenant 1');
        $tenant2 = $this->createTenantMock(2, 'Tenant 2');

        // Same audit number but different tenants - not a duplicate
        $audit1 = $this->createAuditMock($tenant1, 'AUDIT-001');
        $audit2 = $this->createAuditMock($tenant2, 'AUDIT-001');
        $this->auditRepository->method('findAll')->willReturn([$audit1, $audit2]);

        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->findDuplicateEntities();

        $this->assertEmpty($result);
    }

    // ========== findBrokenReferences TESTS ==========

    public function testFindBrokenReferencesReturnsEmptyWhenAllValid(): void
    {
        $tenant = $this->createTenantMock(1, 'Test');
        $asset = $this->createAssetMock($tenant, 'Asset');

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getTenant')->willReturn($tenant);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Risk 1');

        $this->riskRepository->method('findAll')->willReturn([$risk]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);

        // EntityManager contains the asset
        $this->entityManager->method('contains')->willReturn(true);

        $result = $this->service->findBrokenReferences();

        $this->assertEmpty($result);
    }

    public function testFindBrokenReferencesDetectsTenantMismatch(): void
    {
        $tenant1 = $this->createTenantMock(1, 'Tenant 1');
        $tenant2 = $this->createTenantMock(2, 'Tenant 2');

        $asset = $this->createAssetMock($tenant2, 'Asset');  // Different tenant

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getTenant')->willReturn($tenant1);  // Different from asset
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Risk 1');

        $this->riskRepository->method('findAll')->willReturn([$risk]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->entityManager->method('contains')->willReturn(true);

        $result = $this->service->findBrokenReferences();

        $this->assertCount(1, $result);
        $this->assertSame('risk_asset_tenant_mismatch', $result[0]['type']);
    }

    // ========== findMissingRelationships TESTS ==========

    public function testFindMissingRelationshipsDetectsRisksWithoutAsset(): void
    {
        $risk = $this->createMock(Risk::class);

        $qb = $this->createQueryBuilderMock([$risk]);
        $this->riskRepository->method('createQueryBuilder')->willReturn($qb);

        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $result = $this->service->findMissingRelationships();

        $this->assertArrayHasKey('risks_without_asset', $result);
        $this->assertCount(1, $result['risks_without_asset']);
    }

    public function testFindMissingRelationshipsDetectsIncidentsWithoutAssets(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection());

        $qb = $this->createQueryBuilderMock([]);
        $this->riskRepository->method('createQueryBuilder')->willReturn($qb);

        $this->incidentRepository->method('findAll')->willReturn([$incident]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $result = $this->service->findMissingRelationships();

        $this->assertArrayHasKey('incidents_without_assets', $result);
        $this->assertCount(1, $result['incidents_without_assets']);
    }

    public function testFindMissingRelationshipsDetectsApplicableControlsWithoutRisks(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getRisks')->willReturn(new ArrayCollection());
        $control->method('getProtectedAssets')->willReturn(new ArrayCollection([
            $this->createMock(Asset::class)
        ]));

        $qb = $this->createQueryBuilderMock([]);
        $this->riskRepository->method('createQueryBuilder')->willReturn($qb);

        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([$control]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);

        $result = $this->service->findMissingRelationships();

        $this->assertArrayHasKey('controls_without_risks', $result);
        $this->assertCount(1, $result['controls_without_risks']);
    }

    // ========== findInconsistentData TESTS ==========

    public function testFindInconsistentDataDetectsCompletedAuditWithoutDate(): void
    {
        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStatus')->willReturn('completed');
        $audit->method('getActualDate')->willReturn(null);

        $this->auditRepository->method('findAll')->willReturn([$audit]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->findInconsistentData();

        $this->assertArrayHasKey('audits_completed_without_date', $result);
        $this->assertCount(1, $result['audits_completed_without_date']);
    }

    public function testFindInconsistentDataDetectsRisksWithResidualHigherThanInherent(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getResidualRiskLevel')->willReturn(20);
        $risk->method('getInherentRiskLevel')->willReturn(10);

        $this->auditRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([$risk]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->findInconsistentData();

        $this->assertArrayHasKey('risks_residual_higher_than_inherent', $result);
        $this->assertCount(1, $result['risks_residual_higher_than_inherent']);
    }

    public function testFindInconsistentDataDetectsResolvedIncidentWithoutDate(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getStatus')->willReturn('resolved');
        $incident->method('getResolvedAt')->willReturn(null);

        $this->auditRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([$incident]);

        $result = $this->service->findInconsistentData();

        $this->assertArrayHasKey('incidents_resolved_without_date', $result);
        $this->assertCount(1, $result['incidents_resolved_without_date']);
    }

    // ========== getSummaryStatistics TESTS ==========

    public function testGetSummaryStatisticsReturnsAllKeys(): void
    {
        $this->setupEmptyRepositoryMocks();

        $result = $this->service->getSummaryStatistics();

        $this->assertArrayHasKey('total_issues', $result);
        $this->assertArrayHasKey('orphaned_count', $result);
        $this->assertArrayHasKey('missing_relationships_count', $result);
        $this->assertArrayHasKey('broken_references_count', $result);
        $this->assertArrayHasKey('duplicates_count', $result);
        $this->assertArrayHasKey('inconsistent_count', $result);
        $this->assertArrayHasKey('health_score', $result);
    }

    public function testGetSummaryStatisticsReturns100HealthScoreWhenNoEntities(): void
    {
        $this->setupEmptyRepositoryMocks();

        $result = $this->service->getSummaryStatistics();

        $this->assertSame(100, $result['health_score']);
    }

    public function testGetSummaryStatisticsCalculatesTotalIssues(): void
    {
        // Set up orphaned entities
        $this->setupOrphanMocksWithAssets([
            $this->createMock(Asset::class),
            $this->createMock(Asset::class),
        ]);

        // Set up other repositories for the full check
        $this->auditRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);
        $this->documentRepository->method('findAll')->willReturn([]);
        $this->tenantRepository->method('findAll')->willReturn([]);

        $riskQb = $this->createQueryBuilderMock([]);
        $this->riskRepository->method('createQueryBuilder')->willReturn($riskQb);

        $result = $this->service->getSummaryStatistics();

        $this->assertSame(2, $result['orphaned_count']);
        $this->assertGreaterThanOrEqual(2, $result['total_issues']);
    }

    // ========== Helper Methods ==========

    private function createTenantMock(int $id, string $name): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getName')->willReturn($name);
        return $tenant;
    }

    private function createAuditMock(?MockObject $tenant, string $auditNumber): MockObject
    {
        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getTenant')->willReturn($tenant);
        $audit->method('getAuditNumber')->willReturn($auditNumber);
        return $audit;
    }

    private function createAssetMock(?MockObject $tenant, string $name): MockObject
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($tenant);
        $asset->method('getName')->willReturn($name);
        return $asset;
    }

    private function createRiskMock(?MockObject $tenant, string $title): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($tenant);
        $risk->method('getTitle')->willReturn($title);
        return $risk;
    }

    private function createQueryBuilderMock(array $results): MockObject
    {
        // Use getMockBuilder to allow overriding final methods
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($results);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    private function setupEmptyRepositoryMocks(): void
    {
        $this->setupEmptyOrphanMocks();

        $this->auditRepository->method('findAll')->willReturn([]);
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findAll')->willReturn([]);
        $this->documentRepository->method('findAll')->willReturn([]);
        $this->tenantRepository->method('findAll')->willReturn([]);

        $riskQb = $this->createQueryBuilderMock([]);
        $this->riskRepository->method('createQueryBuilder')->willReturn($riskQb);
    }

    private function setupEmptyOrphanMocks(): void
    {
        $qb = $this->createQueryBuilderMock([]);

        $this->assetRepository->method('createQueryBuilder')->willReturn($qb);
        $this->riskRepository->method('createQueryBuilder')->willReturn($qb);
        $this->incidentRepository->method('createQueryBuilder')->willReturn($qb);
        $this->auditRepository->method('createQueryBuilder')->willReturn($qb);
        $this->documentRepository->method('createQueryBuilder')->willReturn($qb);
        $this->trainingRepository->method('createQueryBuilder')->willReturn($qb);
        $this->businessProcessRepository->method('createQueryBuilder')->willReturn($qb);
        $this->bcPlanRepository->method('createQueryBuilder')->willReturn($qb);
        $this->dataBreachRepository->method('createQueryBuilder')->willReturn($qb);
        $this->processingActivityRepository->method('createQueryBuilder')->willReturn($qb);
        $this->supplierRepository->method('createQueryBuilder')->willReturn($qb);
        $this->locationRepository->method('createQueryBuilder')->willReturn($qb);
        $this->personRepository->method('createQueryBuilder')->willReturn($qb);
    }

    private function setupOrphanMocksWithAssets(array $orphanedAssets): void
    {
        $assetQb = $this->createQueryBuilderMock($orphanedAssets);
        $emptyQb = $this->createQueryBuilderMock([]);

        $this->assetRepository->method('createQueryBuilder')->willReturn($assetQb);
        $this->riskRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->incidentRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->auditRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->documentRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->trainingRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->businessProcessRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->bcPlanRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->dataBreachRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->processingActivityRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->supplierRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->locationRepository->method('createQueryBuilder')->willReturn($emptyQb);
        $this->personRepository->method('createQueryBuilder')->willReturn($emptyQb);
    }
}
