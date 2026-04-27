<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for AssetRepository QueryBuilder methods.
 *
 * Requires a real database (APP_ENV=test with configured DATABASE_URL).
 * Run with: php bin/phpunit --group integration tests/Repository/AssetRepositoryIntegrationTest.php
 */
#[Group('integration')]
class AssetRepositoryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AssetRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(AssetRepository::class);

        // Wrap each test in a transaction so DB state is always rolled back
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // findActiveAssets
    // -------------------------------------------------------------------------

    #[Test]
    public function findActiveAssetsReturnsOnlyActiveAssets(): void
    {
        $tenant = $this->createTestTenant();

        $active1 = $this->createAsset($tenant, 'Active Asset A', 'hardware', 'active');
        $active2 = $this->createAsset($tenant, 'Active Asset B', 'software', 'active');
        $this->createAsset($tenant, 'Inactive Asset', 'hardware', 'inactive');
        $this->createAsset($tenant, 'Retired Asset', 'hardware', 'retired');

        $this->em->flush();

        $results = $this->repository->findActiveAssets($tenant);

        $this->assertCount(2, $results);
        $ids = array_map(fn(Asset $a): int => $a->getId(), $results);
        $this->assertContains($active1->getId(), $ids);
        $this->assertContains($active2->getId(), $ids);
    }

    #[Test]
    public function findActiveAssetsReturnsResultsOrderedByNameAscending(): void
    {
        $tenant = $this->createTestTenant();

        $this->createAsset($tenant, 'Zebra Asset', 'software', 'active');
        $this->createAsset($tenant, 'Alpha Asset', 'hardware', 'active');
        $this->createAsset($tenant, 'Mango Asset', 'data', 'active');

        $this->em->flush();

        $results = $this->repository->findActiveAssets($tenant);

        $this->assertCount(3, $results);
        $this->assertSame('Alpha Asset', $results[0]->getName());
        $this->assertSame('Mango Asset', $results[1]->getName());
        $this->assertSame('Zebra Asset', $results[2]->getName());
    }

    #[Test]
    public function findActiveAssetsIsolatesByTenant(): void
    {
        $tenantA = $this->createTestTenant('active-a');
        $tenantB = $this->createTestTenant('active-b');

        $assetA = $this->createAsset($tenantA, 'A Active', 'hardware', 'active');
        $this->createAsset($tenantB, 'B Active', 'hardware', 'active');

        $this->em->flush();

        $results = $this->repository->findActiveAssets($tenantA);

        $this->assertCount(1, $results);
        $this->assertSame($assetA->getId(), $results[0]->getId());
    }

    #[Test]
    public function findActiveAssetsReturnsEmptyWhenNoActiveAssetsExist(): void
    {
        $tenant = $this->createTestTenant();
        $this->createAsset($tenant, 'Retired', 'hardware', 'retired');
        $this->em->flush();

        $results = $this->repository->findActiveAssets($tenant);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // countByType
    // -------------------------------------------------------------------------

    #[Test]
    public function countByTypeReturnsCorrectCountsPerActiveAssetType(): void
    {
        $tenant = $this->createTestTenant();

        $this->createAsset($tenant, 'HW-1', 'hardware', 'active');
        $this->createAsset($tenant, 'HW-2', 'hardware', 'active');
        $this->createAsset($tenant, 'SW-1', 'software', 'active');
        $this->createAsset($tenant, 'HW-Inactive', 'hardware', 'inactive');

        $this->em->flush();

        $results = $this->repository->countByType($tenant);

        $byType = [];
        foreach ($results as $row) {
            $byType[$row['assetType']] = (int) $row['count'];
        }

        $this->assertSame(2, $byType['hardware']);
        $this->assertSame(1, $byType['software']);
        // Inactive asset should NOT be counted
        $this->assertSame(2, $byType['hardware'], 'Inactive hardware should not be counted');
    }

    #[Test]
    public function countByTypeOnlyCountsActiveAssets(): void
    {
        $tenant = $this->createTestTenant();

        $this->createAsset($tenant, 'SW Active', 'software', 'active');
        $this->createAsset($tenant, 'SW Inactive', 'software', 'inactive');
        $this->createAsset($tenant, 'SW Retired', 'software', 'retired');

        $this->em->flush();

        $results = $this->repository->countByType($tenant);

        $this->assertCount(1, $results);
        $this->assertSame('software', $results[0]['assetType']);
        $this->assertSame(1, (int) $results[0]['count']);
    }

    #[Test]
    public function countByTypeIsolatesByTenant(): void
    {
        $tenantA = $this->createTestTenant('type-a');
        $tenantB = $this->createTestTenant('type-b');

        $this->createAsset($tenantA, 'A-HW', 'hardware', 'active');
        $this->createAsset($tenantB, 'B-HW', 'hardware', 'active');
        $this->createAsset($tenantB, 'B-SW', 'software', 'active');

        $this->em->flush();

        $resultsA = $this->repository->countByType($tenantA);

        $this->assertCount(1, $resultsA);
        $this->assertSame('hardware', $resultsA[0]['assetType']);
        $this->assertSame(1, (int) $resultsA[0]['count']);
    }

    #[Test]
    public function countByTypeReturnsEmptyForTenantWithNoActiveAssets(): void
    {
        $tenant = $this->createTestTenant();
        $this->createAsset($tenant, 'Retired', 'hardware', 'retired');
        $this->em->flush();

        $results = $this->repository->countByType($tenant);

        $this->assertSame([], $results);
    }

    #[Test]
    public function countByTypeReturnsEmptyForTenantWithNoAssets(): void
    {
        $tenant = $this->createTestTenant();
        $this->em->flush();

        $results = $this->repository->countByType($tenant);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTestTenant(string $suffix = ''): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant ' . $suffix);
        $tenant->setCode('ast_' . uniqid() . $suffix);
        $this->em->persist($tenant);
        return $tenant;
    }

    private function createAsset(
        Tenant $tenant,
        string $name,
        string $assetType,
        string $status,
    ): Asset {
        $asset = new Asset();
        $asset->setTenant($tenant);
        $asset->setName($name);
        $asset->setAssetType($assetType);
        $asset->setOwner('Integration Test Owner');
        $asset->setStatus($status);
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);
        $this->em->persist($asset);
        return $asset;
    }
}
