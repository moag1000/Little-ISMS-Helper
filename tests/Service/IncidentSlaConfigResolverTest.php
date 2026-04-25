<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\IncidentSlaConfigResolver;
use App\Service\IncidentSlaView;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Phase 8M.2 — Unit-Tests für IncidentSlaConfigResolver Ceiling-Merge pro Severity.
 *
 * Testet die Holding-Hierarchie-Semantik für Incident-SLAs:
 * Child-Tenant darf nur NIEDRIGERE (schnellere/strengere) Stunden haben.
 * min(child, parent) für alle Hours-Felder. Nullable-Felder: null = kein Limit.
 */
#[AllowMockObjectsWithoutExpectations]
class IncidentSlaConfigResolverTest extends TestCase
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

    /** @return Stub&IncidentSlaConfig */
    private function makeEntity(string $severity, int $response, ?int $escalation, ?int $resolution): Stub
    {
        $entity = $this->createStub(IncidentSlaConfig::class);
        $entity->method('getSeverity')->willReturn($severity);
        $entity->method('getResponseHours')->willReturn($response);
        $entity->method('getEscalationHours')->willReturn($escalation);
        $entity->method('getResolutionHours')->willReturn($resolution);
        return $entity;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Root-Tenant ohne Parent: Config wird unverändert zurückgegeben.
     */
    public function testRootTenantReturnsOwnConfig(): void
    {
        $tenant = $this->makeTenant(1, []);
        $entity = $this->makeEntity('critical', 2, 4, 8);

        $repository = $this->createMock(IncidentSlaConfigRepository::class);
        $repository->expects($this->once())
            ->method('findByTenantAndSeverity')
            ->with($tenant, 'critical')
            ->willReturn($entity);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($tenant, 'critical');

        $this->assertSame(2, $view->responseHours);
        $this->assertSame(4, $view->escalationHours);
        $this->assertSame(8, $view->resolutionHours);
    }

    /**
     * Child mit LAXEREN SLAs als Parent → auf Parent-Werte gecapped.
     */
    public function testChildWithLaxerSlaIsCappedByParent(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        // Child: laxere SLAs (mehr Stunden = weniger streng)
        $childEntity  = $this->makeEntity('high', 8, 24, 72);
        // Parent: strengere Ceiling (weniger Stunden)
        $parentEntity = $this->makeEntity('high', 4, 12, 48);

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')
            ->willReturnMap([
                [$child,  'high', $childEntity],
                [$parent, 'high', $parentEntity],
            ]);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'high');

        // Ceiling: min(child, parent) → Parent-Werte gewinnen
        $this->assertSame(4, $view->responseHours);
        $this->assertSame(12, $view->escalationHours);
        $this->assertSame(48, $view->resolutionHours);
    }

    /**
     * Child mit STRENGEREN SLAs als Parent → bleibt unverändert.
     */
    public function testChildWithStricterSlaRemainsUnchanged(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        // Child: strengere SLAs (weniger Stunden)
        $childEntity  = $this->makeEntity('high', 2, 6, 24);
        // Parent: laxeres Ceiling
        $parentEntity = $this->makeEntity('high', 4, 12, 48);

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')
            ->willReturnMap([
                [$child,  'high', $childEntity],
                [$parent, 'high', $parentEntity],
            ]);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'high');

        // Child-Werte sind kleiner und bleiben erhalten
        $this->assertSame(2, $view->responseHours);
        $this->assertSame(6, $view->escalationHours);
        $this->assertSame(24, $view->resolutionHours);
    }

    /**
     * 3-Ebenen-Hierarchie: Holding → Sub-Holding → Child.
     */
    public function testThreeLevelHierarchyCeiling(): void
    {
        $root       = $this->makeTenant(1, []);
        $subHolding = $this->makeTenant(2, [$root]);
        $child      = $this->makeTenant(3, [$subHolding, $root]);

        $rootEntity       = $this->makeEntity('critical', 1, 2, 4);   // strengste Ceiling
        $subHoldingEntity = $this->makeEntity('critical', 2, 4, 8);   // laxer als root
        $childEntity      = $this->makeEntity('critical', 4, 8, 16);  // am laxesten

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')
            ->willReturnMap([
                [$child,      'critical', $childEntity],
                [$subHolding, 'critical', $subHoldingEntity],
                [$root,       'critical', $rootEntity],
            ]);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'critical');

        // min(4,2)=2, dann min(2,1)=1
        $this->assertSame(1, $view->responseHours);
        $this->assertSame(2, $view->escalationHours);
        $this->assertSame(4, $view->resolutionHours);
    }

    /**
     * Nullable escalationHours/resolutionHours: null = kein Limit (unendlich lax).
     * Wenn Parent null hat und Child einen Wert — Child-Wert gewinnt (ist strenger).
     */
    public function testNullableHoursParentNullChildValueChildWins(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        // Parent hat null = kein Escalation/Resolution-Limit gesetzt
        $parentEntity = $this->makeEntity('medium', 8, null, null);
        // Child hat konkrete Werte — ist strenger
        $childEntity  = $this->makeEntity('medium', 4, 12, 48);

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')
            ->willReturnMap([
                [$child,  'medium', $childEntity],
                [$parent, 'medium', $parentEntity],
            ]);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'medium');

        // responseHours: min(4, 8) = 4 (child strenger)
        // escalationHours: minNullable(12, null) = 12 (child hat Wert, parent nicht)
        // resolutionHours: minNullable(48, null) = 48
        $this->assertSame(4, $view->responseHours);
        $this->assertSame(12, $view->escalationHours);
        $this->assertSame(48, $view->resolutionHours);
    }

    /**
     * Nullable: Child null, Parent hat Wert → Parent-Wert wird übernommen (ist strenger).
     */
    public function testNullableHoursChildNullParentValueParentWins(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        // Child hat null = kein eigenes Limit gesetzt
        $childEntity  = $this->makeEntity('low', 24, null, null);
        // Parent setzt konkrete Ceiling
        $parentEntity = $this->makeEntity('low', 8, 48, 168);

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')
            ->willReturnMap([
                [$child,  'low', $childEntity],
                [$parent, 'low', $parentEntity],
            ]);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'low');

        // responseHours: min(24, 8) = 8 (parent strenger)
        // escalationHours: minNullable(null, 48) = 48 (parent hat Wert)
        // resolutionHours: minNullable(null, 168) = 168
        $this->assertSame(8, $view->responseHours);
        $this->assertSame(48, $view->escalationHours);
        $this->assertSame(168, $view->resolutionHours);
    }

    /**
     * Child ohne eigene Config → Default + Ceiling mit Parent.
     */
    public function testChildWithoutConfigUsesDefaultThenMergesWithParent(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $parentEntity = $this->makeEntity('critical', 1, 2, 4);

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')
            ->willReturnMap([
                [$child,  'critical', null],
                [$parent, 'critical', $parentEntity],
            ]);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'critical');

        // Default für 'critical' (typisch 2h), Ceiling mit parent (1h response) → min = 1
        $this->assertSame(1, $view->responseHours);
    }

    /**
     * Cache-Invalidation per Tenant löscht nur Einträge für diesen Tenant.
     */
    public function testCacheInvalidationPerTenant(): void
    {
        $tenant = $this->makeTenant(5, []);
        $entity1 = $this->makeEntity('high', 4, 8, 24);
        $entity2 = $this->makeEntity('high', 2, 4, 12);

        $repository = $this->createMock(IncidentSlaConfigRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findByTenantAndSeverity')
            ->with($tenant, 'high')
            ->willReturnOnConsecutiveCalls($entity1, $entity2);

        $resolver = new IncidentSlaConfigResolver($repository);

        $view1 = $resolver->resolveFor($tenant, 'high');
        $this->assertSame(4, $view1->responseHours);

        $resolver->invalidate($tenant);

        $view2 = $resolver->resolveFor($tenant, 'high');
        $this->assertSame(2, $view2->responseHours);
    }

    /**
     * Kein Config in der gesamten Hierarchie → Default-Fallback.
     */
    public function testNoConfigAnywhereFallsBackToDefault(): void
    {
        $parent = $this->makeTenant(10, []);
        $child  = $this->makeTenant(20, [$parent]);

        $repository = $this->createStub(IncidentSlaConfigRepository::class);
        $repository->method('findByTenantAndSeverity')->willReturn(null);

        $resolver = new IncidentSlaConfigResolver($repository);
        $view = $resolver->resolveFor($child, 'medium');
        $defaultView = IncidentSlaView::fromDefault('medium');

        $this->assertSame($defaultView->responseHours, $view->responseHours);
        $this->assertSame($defaultView->severity, $view->severity);
    }
}
