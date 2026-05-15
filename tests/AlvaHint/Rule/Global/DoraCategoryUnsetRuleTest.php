<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\DoraCategoryUnsetRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\SupplierRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoraCategoryUnsetRule.
 *
 * Fires when tenant.doraEntityCategory=none but entity-level DORA flags exist.
 * Suppressed when tenant is already DORA-obligated, or when no entities are flagged.
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraCategoryUnsetRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    private function makeRule(int $supplierCount = 0, int $assetCount = 0): DoraCategoryUnsetRule
    {
        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('countByTenantAndDoraRelevant')->willReturn($supplierCount);

        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('countByTenantAndDoraRelevant')->willReturn($assetCount);

        return new DoraCategoryUnsetRule($supplierRepo, $assetRepo);
    }

    #[Test]
    public function returnsNullWhenTenantIsAlreadyDoraObligated(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);

        // Even if suppliers are flagged, no hint — contradiction does not exist
        $rule = $this->makeRule(supplierCount: 3);
        self::assertNull($rule->evaluate($tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenTenantIsNoneAndNoEntitiesFlagged(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(supplierCount: 0, assetCount: 0);
        self::assertNull($rule->evaluate($tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenTenantIsNoneButSuppliersAreFlagged(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(supplierCount: 2, assetCount: 0);
        $hint = $rule->evaluate($tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.dora_category_unset', $hint->key);
    }

    #[Test]
    public function returnsHintWhenTenantIsNoneButAssetsAreFlagged(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(supplierCount: 0, assetCount: 1);
        $hint = $rule->evaluate($tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.dora_category_unset', $hint->key);
    }

    #[Test]
    public function hintBodyParamsContainCombinedCount(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(supplierCount: 2, assetCount: 3);
        $hint = $rule->evaluate($tenant, $this->user);

        self::assertNotNull($hint);
        self::assertArrayHasKey('%count%', $hint->bodyTranslationParams);
        self::assertSame('5', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function hintIsTierTwoDismissible(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(supplierCount: 1);
        $hint = $rule->evaluate($tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(2, $hint->priorityTier);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function hintActionRouteIsAdminTenantComplianceSettings(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(supplierCount: 1);
        $hint = $rule->evaluate($tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('admin_tenant_compliance_settings_current', $hint->actionRoute);
    }

    #[Test]
    public function requiresNis2DoraModule(): void
    {
        $rule = $this->makeRule();
        self::assertSame(['nis2_dora'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToCisoDashboardAndAdminTenantPages(): void
    {
        $rule = $this->makeRule();
        $pages = $rule->appliesToPages();

        self::assertContains('dashboard_ciso', $pages);
        self::assertContains('admin_tenant', $pages);
    }

    #[Test]
    public function hintVariantIsWarning(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $rule = $this->makeRule(assetCount: 1);
        $hint = $rule->evaluate($tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('warning', $hint->variant);
    }
}
