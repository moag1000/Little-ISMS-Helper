<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\AssetRepository;
use App\Repository\CorporateGovernanceRepository;
use App\Service\AssetService;
use App\Service\CorporateStructureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AssetServiceTest extends TestCase
{
    private MockObject $assetRepository;
    private MockObject $corporateStructureService;
    private MockObject $governanceRepository;
    private AssetService $service;

    protected function setUp(): void
    {
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->governanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->service = new AssetService(
            $this->assetRepository,
            $this->corporateStructureService,
            $this->governanceRepository
        );
    }

    public function testGetAssetsForTenantWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);
        $assets = [$this->createMock(Asset::class)];

        $this->assetRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($assets);

        $result = $this->service->getAssetsForTenant($tenant);

        $this->assertSame($assets, $result);
    }

    public function testGetAssetsForTenantWithHierarchicalGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $inheritedAssets = [
            $this->createMock(Asset::class),
            $this->createMock(Asset::class),
        ];

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'asset')
            ->willReturn($governance);

        $this->assetRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedAssets);

        $result = $this->service->getAssetsForTenant($child);

        $this->assertSame($inheritedAssets, $result);
        $this->assertCount(2, $result);
    }

    public function testGetAssetsForTenantWithIndependentGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownAssets = [$this->createMock(Asset::class)];

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'asset')
            ->willReturn($governance);

        $this->assetRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownAssets);

        $result = $this->service->getAssetsForTenant($child);

        $this->assertSame($ownAssets, $result);
    }

    public function testGetAssetsForTenantFallbackToDefaultGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'asset')
            ->willReturn(null);

        $defaultGovernance = $this->createGovernance('shared');
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn($defaultGovernance);

        $ownAssets = [$this->createMock(Asset::class)];
        $this->assetRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownAssets);

        $result = $this->service->getAssetsForTenant($child);

        $this->assertSame($ownAssets, $result);
    }

    public function testGetAssetInheritanceInfoWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);

        $info = $this->service->getAssetInheritanceInfo($tenant);

        $this->assertFalse($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    public function testGetAssetInheritanceInfoWithHierarchicalParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'asset')
            ->willReturn($governance);

        $info = $this->service->getAssetInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertTrue($info['canInherit']);
        $this->assertSame('hierarchical', $info['governanceModel']);
    }

    public function testGetAssetInheritanceInfoWithSharedParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('shared');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'asset')
            ->willReturn($governance);

        $info = $this->service->getAssetInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertSame('shared', $info['governanceModel']);
    }

    public function testIsInheritedAssetTrue(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($parentTenant);

        $this->assertTrue($this->service->isInheritedAsset($asset, $childTenant));
    }

    public function testIsInheritedAssetFalse(): void
    {
        $tenant = $this->createTenant(1, null);

        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($tenant);

        $this->assertFalse($this->service->isInheritedAsset($asset, $tenant));
    }

    public function testIsInheritedAssetWithNullTenant(): void
    {
        $tenant = $this->createTenant(1, null);

        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn(null);

        $this->assertFalse($this->service->isInheritedAsset($asset, $tenant));
    }

    public function testIsInheritedAssetWithNullIds(): void
    {
        $tenant1 = $this->createTenant(null, null);
        $tenant2 = $this->createTenant(null, null);

        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($tenant1);

        $this->assertFalse($this->service->isInheritedAsset($asset, $tenant2));
    }

    public function testCanEditAssetOwnAsset(): void
    {
        $tenant = $this->createTenant(1, null);

        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($tenant);

        $this->assertTrue($this->service->canEditAsset($asset, $tenant));
    }

    public function testCanEditAssetInheritedAsset(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($parentTenant);

        $this->assertFalse($this->service->canEditAsset($asset, $childTenant));
    }

    public function testServiceWorksWithoutOptionalDependencies(): void
    {
        $simpleService = new AssetService($this->assetRepository, null, null);

        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownAssets = [$this->createMock(Asset::class)];

        $this->assetRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownAssets);

        $result = $simpleService->getAssetsForTenant($child);

        $this->assertSame($ownAssets, $result);
    }

    public function testGetAssetInheritanceInfoWithNoGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $this->governanceRepository->method('findGovernanceForScope')
            ->willReturn(null);
        $this->governanceRepository->method('findDefaultGovernance')
            ->willReturn(null);

        $info = $this->service->getAssetInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    private function createTenant(?int $id, ?Tenant $parent): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getParent')->willReturn($parent);
        return $tenant;
    }

    private function createGovernance(string $modelValue): MockObject
    {
        $model = match ($modelValue) {
            'hierarchical' => GovernanceModel::HIERARCHICAL,
            'shared' => GovernanceModel::SHARED,
            'independent' => GovernanceModel::INDEPENDENT,
            default => GovernanceModel::INDEPENDENT,
        };

        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($model);
        return $governance;
    }
}
