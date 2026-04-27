<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\KpiThresholdConfig;
use App\Entity\Tenant;
use App\Repository\KpiThresholdConfigRepository;
use App\Service\KpiThresholdConfigResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 8M.3 — Unit-Tests für KpiThresholdConfigResolver Fallback-Kaskade.
 *
 * Merge-Semantik: Fallback (Pick-First), kein numerischer Merge.
 * Child-Tenant → nächster Ancestor → … → Root → Service-Default.
 */
#[AllowMockObjectsWithoutExpectations]
class KpiThresholdConfigResolverTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return Stub&Tenant */
    private function makeTenant(int $id, array $ancestors = []): Stub
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn($ancestors);
        return $tenant;
    }

    /** @return Stub&KpiThresholdConfig */
    private function makeConfig(int $good, int $warning): Stub
    {
        $config = $this->createStub(KpiThresholdConfig::class);
        $config->method('getGoodThreshold')->willReturn($good);
        $config->method('getWarningThreshold')->willReturn($warning);
        return $config;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Child hat eigenen Config-Eintrag → wird sofort verwendet (Pick-First).
     */
    #[Test]
    public function testChildConfigUsedFirst(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $childRow = $this->makeConfig(90, 70);

        // Nur Child-Lookup wird aufgerufen (Pick-First schlägt sofort an)
        $repository = $this->createMock(KpiThresholdConfigRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['tenant' => $child, 'kpiKey' => 'assets'])
            ->willReturn($childRow);

        $resolver = new KpiThresholdConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'assets', 80, 60);

        $this->assertSame(90, $view->goodThreshold);
        $this->assertSame(70, $view->warningThreshold);
    }

    /**
     * Child hat keinen Eintrag → fällt auf Parent zurück.
     */
    #[Test]
    public function testFallsBackToParentWhenChildHasNoConfig(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $parentRow = $this->makeConfig(85, 65);

        $repository = $this->createStub(KpiThresholdConfigRepository::class);
        $repository->method('findOneBy')
            ->willReturnMap([
                [['tenant' => $child,  'kpiKey' => 'controls'], null],
                [['tenant' => $parent, 'kpiKey' => 'controls'], $parentRow],
            ]);

        $resolver = new KpiThresholdConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'controls', 80, 60);

        $this->assertSame(85, $view->goodThreshold);
        $this->assertSame(65, $view->warningThreshold);
    }

    /**
     * 3-Ebenen-Hierarchie: nur Root hat Config → Child erbt von Root.
     */
    #[Test]
    public function testThreeLevelFallbackToRoot(): void
    {
        $root       = $this->makeTenant(1, []);
        $subHolding = $this->makeTenant(2, [$root]);
        $child      = $this->makeTenant(3, [$subHolding, $root]);

        $rootRow = $this->makeConfig(75, 55);

        $repository = $this->createStub(KpiThresholdConfigRepository::class);
        $repository->method('findOneBy')
            ->willReturnMap([
                [['tenant' => $child,      'kpiKey' => 'incidents'], null],
                [['tenant' => $subHolding, 'kpiKey' => 'incidents'], null],
                [['tenant' => $root,       'kpiKey' => 'incidents'], $rootRow],
            ]);

        $resolver = new KpiThresholdConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'incidents', 80, 60);

        $this->assertSame(75, $view->goodThreshold);
        $this->assertSame(55, $view->warningThreshold);
    }

    /**
     * Kein Eintrag in gesamter Hierarchie → Service-Default wird verwendet.
     */
    #[Test]
    public function testNoConfigInHierarchyUsesServiceDefault(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $repository = $this->createStub(KpiThresholdConfigRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $resolver = new KpiThresholdConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'risks', 80, 60);

        $this->assertSame(80, $view->goodThreshold);
        $this->assertSame(60, $view->warningThreshold);
    }

    /**
     * Root-Tenant ohne Parent mit eigenem Config-Eintrag.
     */
    #[Test]
    public function testRootTenantWithOwnConfig(): void
    {
        $root = $this->makeTenant(1, []);

        $rootRow = $this->makeConfig(95, 80);

        $repository = $this->createMock(KpiThresholdConfigRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['tenant' => $root, 'kpiKey' => 'soa'])
            ->willReturn($rootRow);

        $resolver = new KpiThresholdConfigResolver($repository);
        $view = $resolver->resolveFor($root, 'soa', 80, 60);

        $this->assertSame(95, $view->goodThreshold);
        $this->assertSame(80, $view->warningThreshold);
    }

    /**
     * Cache-Treffer verhindert erneuten DB-Zugriff bei identischem Aufruf.
     */
    #[Test]
    public function testCachePreventsDuplicateDbLookup(): void
    {
        $tenant = $this->makeTenant(5, []);
        $row = $this->makeConfig(80, 60);

        // Nur einmal DB-Zugriff erwartet; zweiter Aufruf aus Cache bedient
        $repository = $this->createMock(KpiThresholdConfigRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->willReturn($row);

        $resolver = new KpiThresholdConfigResolver($repository);

        $view1 = $resolver->resolveFor($tenant, 'assets', 80, 60);
        $view2 = $resolver->resolveFor($tenant, 'assets', 80, 60); // aus Cache

        $this->assertSame($view1->goodThreshold, $view2->goodThreshold);
    }

    /**
     * Cache-Invalidation: nach invalidate() wird erneut aus Repository geladen.
     */
    #[Test]
    public function testCacheInvalidation(): void
    {
        $tenant = $this->makeTenant(5, []);

        $row1 = $this->makeConfig(80, 60);
        $row2 = $this->makeConfig(90, 70);

        $repository = $this->createMock(KpiThresholdConfigRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls($row1, $row2);

        $resolver = new KpiThresholdConfigResolver($repository);

        $view1 = $resolver->resolveFor($tenant, 'assets', 80, 60);
        $this->assertSame(80, $view1->goodThreshold);

        $resolver->invalidate($tenant);

        $view2 = $resolver->resolveFor($tenant, 'assets', 80, 60);
        $this->assertSame(90, $view2->goodThreshold);
    }

    /**
     * invalidateAll() leert den gesamten Cache.
     */
    #[Test]
    public function testInvalidateAll(): void
    {
        $tenant1 = $this->makeTenant(1, []);
        $tenant2 = $this->makeTenant(2, []);

        $row = $this->makeConfig(80, 60);

        $repository = $this->createMock(KpiThresholdConfigRepository::class);
        // Erste Runde: 2 Calls, nach invalidateAll 2 weitere Calls = 4 total
        $repository->expects($this->exactly(4))
            ->method('findOneBy')
            ->willReturn($row);

        $resolver = new KpiThresholdConfigResolver($repository);

        // Beide Tenants cachen (2 Calls)
        $resolver->resolveFor($tenant1, 'assets', 80, 60);
        $resolver->resolveFor($tenant2, 'assets', 80, 60);

        $resolver->invalidateAll();

        // Nach invalidateAll(): erneut 2 DB-Calls nötig
        $resolver->resolveFor($tenant1, 'assets', 80, 60);
        $resolver->resolveFor($tenant2, 'assets', 80, 60);
    }
}
