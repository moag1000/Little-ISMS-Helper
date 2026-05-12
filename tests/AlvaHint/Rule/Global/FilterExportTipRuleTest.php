<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\FilterExportTipRule;
use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FilterExportTipRule (Sprint-3 Alva-Hint Rule 6).
 *
 * Covers:
 *  - Fires when risk count > 50
 *  - Fires when asset count > 50
 *  - Suppressed when both < 50
 *  - Tier-3 info tip, GET action
 *  - No required modules (core)
 *  - ROLE_MANAGER required
 */
#[AllowMockObjectsWithoutExpectations]
final class FilterExportTipRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenBothCountsBelowThreshold(): void
    {
        $riskRepo = $this->createMock(RiskRepository::class);
        $riskRepo->method('findByTenant')->willReturn(array_fill(0, 30, new Risk()));
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenant')->willReturn(array_fill(0, 20, new Asset()));

        $rule = new FilterExportTipRule($riskRepo, $assetRepo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenRiskCountOver50(): void
    {
        $riskRepo = $this->createMock(RiskRepository::class);
        $riskRepo->method('findByTenant')->willReturn(array_fill(0, 55, new Risk()));
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenant')->willReturn(array_fill(0, 10, new Asset()));

        $rule = new FilterExportTipRule($riskRepo, $assetRepo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.filter_export_tip', $hint->key);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('info', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_filtered_export_entity', $hint->actionRoute);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
    }

    #[Test]
    public function returnsHintWhenAssetCountOver50(): void
    {
        $riskRepo = $this->createMock(RiskRepository::class);
        $riskRepo->method('findByTenant')->willReturn(array_fill(0, 10, new Risk()));
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenant')->willReturn(array_fill(0, 60, new Asset()));

        $rule = new FilterExportTipRule($riskRepo, $assetRepo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.filter_export_tip', $hint->key);
    }

    #[Test]
    public function hasNoRequiredModules(): void
    {
        $rule = new FilterExportTipRule(
            $this->createMock(RiskRepository::class),
            $this->createMock(AssetRepository::class),
        );
        self::assertSame([], $rule->requiredModules());
    }
}
