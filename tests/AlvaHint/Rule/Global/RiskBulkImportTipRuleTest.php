<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\RiskBulkImportTipRule;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RiskBulkImportTipRule.
 *
 * Covers: threshold trigger, below-threshold suppression, module gating,
 * page scoping, action method, and required roles.
 */
#[AllowMockObjectsWithoutExpectations]
final class RiskBulkImportTipRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenRiskCountExceedsThreshold(): void
    {
        $risks = array_fill(0, 31, new Risk());
        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findByTenant')->willReturn($risks);

        $rule = new RiskBulkImportTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.risk_bulk_import_tip', $hint->key);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_bulk_import_index', $hint->actionRoute);
        self::assertSame(['entityType' => 'risk'], $hint->actionRouteParams);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('info', $hint->variant);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    #[Test]
    public function returnsNullWhenRiskCountAtThreshold(): void
    {
        $risks = array_fill(0, 30, new Risk());
        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findByTenant')->willReturn($risks);

        $rule = new RiskBulkImportTipRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenRiskCountBelowThreshold(): void
    {
        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findByTenant')->willReturn([]);

        $rule = new RiskBulkImportTipRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function requiresRisksModule(): void
    {
        $rule = new RiskBulkImportTipRule($this->createMock(RiskRepository::class));
        self::assertSame(['risks'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToRiskIndexPage(): void
    {
        $rule = new RiskBulkImportTipRule($this->createMock(RiskRepository::class));
        self::assertContains('app_risk_index', $rule->appliesToPages());
    }

    #[Test]
    public function hintBodyContainsCountParameter(): void
    {
        $risks = array_fill(0, 35, new Risk());
        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findByTenant')->willReturn($risks);

        $rule = new RiskBulkImportTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '35'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function hintIsDismissible(): void
    {
        $risks = array_fill(0, 31, new Risk());
        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findByTenant')->willReturn($risks);

        $rule = new RiskBulkImportTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertTrue($hint->dismissible);
    }
}
