<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use App\Repository\RiskApprovalConfigRepository;
use App\Service\RiskApprovalConfigResolver;
use App\Service\RiskApprovalConfigView;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Phase 8M.1 — Unit-Tests für RiskApprovalConfigResolver Ceiling-Merge.
 *
 * Testet die Holding-Hierarchie-Semantik:
 * Child-Tenant darf nur NIEDRIGERE (strengere) Schwellwerte haben.
 * min(child, parent) für alle drei Threshold-Felder.
 */
#[AllowMockObjectsWithoutExpectations]
class RiskApprovalConfigResolverTest extends TestCase
{
    private RiskApprovalConfigResolver $resolver;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return MockObject&RiskApprovalConfigRepository */
    private function makeRepository(): MockObject
    {
        return $this->createMock(RiskApprovalConfigRepository::class);
    }

    /** @return Stub&RiskApprovalConfigRepository */
    private function makeRepositoryStub(): Stub
    {
        return $this->createStub(RiskApprovalConfigRepository::class);
    }

    /** @return Stub&Tenant */
    private function makeTenant(int $id, array $ancestors = []): Stub
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn($ancestors);
        return $tenant;
    }

    /** @return Stub&RiskApprovalConfig */
    private function makeConfig(int $auto, int $manager, int $executive): Stub
    {
        $config = $this->createStub(RiskApprovalConfig::class);
        $config->method('getThresholdAutomatic')->willReturn($auto);
        $config->method('getThresholdManager')->willReturn($manager);
        $config->method('getThresholdExecutive')->willReturn($executive);
        return $config;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Root-Tenant ohne Parent: Config wird unverändert zurückgegeben.
     */
    public function testRootTenantNoParentReturnsOwnConfig(): void
    {
        $tenant = $this->makeTenant(1, []);
        $config = $this->makeConfig(3, 7, 25);

        $repository = $this->makeRepository();
        $repository->expects($this->once())
            ->method('findByTenant')
            ->with($tenant)
            ->willReturn($config);

        $resolver = new RiskApprovalConfigResolver($repository);
        $view = $resolver->resolveFor($tenant);

        $this->assertSame(3, $view->thresholdAutomatic);
        $this->assertSame(7, $view->thresholdManager);
        $this->assertSame(25, $view->thresholdExecutive);
    }

    /**
     * Child mit LAXEREN Schwellwerten als Parent → auf Parent-Werte gecapped.
     */
    public function testChildWithLaxerThresholdsIsCappedByParent(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        // Child will laxere Schwellen (höhere Werte) setzen — wird gecapped
        $childConfig  = $this->makeConfig(10, 20, 50); // laxer als parent
        $parentConfig = $this->makeConfig(3, 7, 25);   // strengere Ceiling

        $repository = $this->makeRepositoryStub();
        $repository->method('findByTenant')
            ->willReturnMap([
                [$child,  $childConfig],
                [$parent, $parentConfig],
            ]);

        $resolver = new RiskApprovalConfigResolver($repository);
        $view = $resolver->resolveFor($child);

        // Ceiling: min(child, parent) → Parent-Werte gewinnen
        $this->assertSame(3, $view->thresholdAutomatic);
        $this->assertSame(7, $view->thresholdManager);
        $this->assertSame(25, $view->thresholdExecutive);
    }

    /**
     * Child mit STRENGEREN Schwellwerten als Parent → bleibt unverändert.
     */
    public function testChildWithStricterThresholdsRemainsUnchanged(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        // Child setzt strengere (niedrigere) Werte — darf so bleiben
        $childConfig  = $this->makeConfig(2, 5, 15); // strenger als parent
        $parentConfig = $this->makeConfig(3, 7, 25); // laxeres Ceiling

        $repository = $this->makeRepositoryStub();
        $repository->method('findByTenant')
            ->willReturnMap([
                [$child,  $childConfig],
                [$parent, $parentConfig],
            ]);

        $resolver = new RiskApprovalConfigResolver($repository);
        $view = $resolver->resolveFor($child);

        // min(child, parent) → Child-Werte sind kleiner und bleiben
        $this->assertSame(2, $view->thresholdAutomatic);
        $this->assertSame(5, $view->thresholdManager);
        $this->assertSame(15, $view->thresholdExecutive);
    }

    /**
     * 3-Ebenen-Hierarchie: Holding → Sub-Holding → Child.
     * Root setzt das absolute Maximum; alle Descendants werden gecapped.
     */
    public function testThreeLevelHierarchyCeiling(): void
    {
        $root       = $this->makeTenant(1, []);
        $subHolding = $this->makeTenant(2, [$root]);
        $child      = $this->makeTenant(3, [$subHolding, $root]); // Ancestors: Parent zuerst, Root zuletzt

        $rootConfig       = $this->makeConfig(3, 7, 25);  // strengstes Ceiling
        $subHoldingConfig = $this->makeConfig(5, 10, 30); // laxer als root → wird gecapped
        $childConfig      = $this->makeConfig(8, 15, 40); // am laxesten → wird gecapped

        $repository = $this->makeRepositoryStub();
        $repository->method('findByTenant')
            ->willReturnMap([
                [$child,      $childConfig],
                [$subHolding, $subHoldingConfig],
                [$root,       $rootConfig],
            ]);

        $resolver = new RiskApprovalConfigResolver($repository);
        $view = $resolver->resolveFor($child);

        // Nach 2 Ceiling-Schritten: min(child=8, sub=5) = 5, dann min(5, root=3) = 3
        $this->assertSame(3, $view->thresholdAutomatic);
        $this->assertSame(7, $view->thresholdManager);
        $this->assertSame(25, $view->thresholdExecutive);
    }

    /**
     * Child ohne eigene Config erbt komplett vom ersten Ancestor mit Config.
     * Default (3/7/25) wird mit Parent-Ceiling geclippt.
     */
    public function testChildWithoutConfigInheritsFromAncestor(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $parentConfig = $this->makeConfig(4, 8, 20);

        $repository = $this->makeRepositoryStub();
        $repository->method('findByTenant')
            ->willReturnMap([
                [$child,  null],         // kein eigener Eintrag
                [$parent, $parentConfig],
            ]);

        $resolver = new RiskApprovalConfigResolver($repository);
        $view = $resolver->resolveFor($child);

        // Default = (3, 7, 25), dann Ceiling mit parent (4, 8, 20):
        // min(3,4)=3, min(7,8)=7, min(25,20)=20
        $this->assertSame(3, $view->thresholdAutomatic);
        $this->assertSame(7, $view->thresholdManager);
        $this->assertSame(20, $view->thresholdExecutive);
    }

    /**
     * Cache-Invalidation: nach invalidate() wird erneut aus Repository geladen.
     */
    public function testCacheInvalidationCausesReload(): void
    {
        $tenant = $this->makeTenant(1, []);
        $config1 = $this->makeConfig(3, 7, 25);
        $config2 = $this->makeConfig(2, 5, 15);

        $repository = $this->makeRepository();
        $repository->expects($this->exactly(2))
            ->method('findByTenant')
            ->willReturnOnConsecutiveCalls($config1, $config2);

        $resolver = new RiskApprovalConfigResolver($repository);

        $view1 = $resolver->resolveFor($tenant);
        $this->assertSame(3, $view1->thresholdAutomatic);

        // Nach Invalidation: erneuter Datenbankzugriff
        $resolver->invalidate($tenant);
        $view2 = $resolver->resolveFor($tenant);
        $this->assertSame(2, $view2->thresholdAutomatic);
    }

    /**
     * Kein Ancestor mit Config und kein eigener Eintrag → Service-Defaults.
     */
    public function testNoConfigAnywhereFallsBackToDefaults(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $repository = $this->makeRepositoryStub();
        $repository->method('findByTenant')->willReturn(null);

        $resolver = new RiskApprovalConfigResolver($repository);
        $view = $resolver->resolveFor($child);
        $defaults = RiskApprovalConfigView::defaults();

        $this->assertSame($defaults->thresholdAutomatic, $view->thresholdAutomatic);
        $this->assertSame($defaults->thresholdManager, $view->thresholdManager);
        $this->assertSame($defaults->thresholdExecutive, $view->thresholdExecutive);
    }
}
