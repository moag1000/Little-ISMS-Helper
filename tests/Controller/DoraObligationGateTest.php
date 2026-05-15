<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests verifying Tenant.doraEntityCategory as seen from controller context.
 *
 * These are pure unit tests — no DB required. Integration-level redirect
 * behaviour (HTTP 302 for non-obligated tenants) is covered by the existing
 * functional test suite that runs against a fully-migrated DB.
 */
final class DoraObligationGateTest extends TestCase
{
    #[Test]
    public function newTenantIsNotDoraObligatedByDefault(): void
    {
        $tenant = new Tenant();
        self::assertFalse($tenant->isDoraObligated());
        self::assertSame(Tenant::DORA_NONE, $tenant->getDoraEntityCategory());
    }

    #[Test]
    public function financialEntityTenantIsDoraObligated(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);

        self::assertTrue($tenant->isDoraObligated());
    }

    #[Test]
    public function criticalIctThirdPartyTenantIsDoraObligated(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_CRITICAL_ICT_THIRD_PARTY);

        self::assertTrue($tenant->isDoraObligated());
    }

    #[Test]
    public function nonObligatedTenantWouldTriggerControllerRedirect(): void
    {
        // Documents the controller gate logic: DoraComplianceController and
        // DoraRoiController both call $tenant->isDoraObligated() and redirect
        // to app_dashboard when false.
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        // Simulates the controller condition: !$tenant->isDoraObligated()
        $shouldRedirect = !$tenant->isDoraObligated();

        self::assertTrue($shouldRedirect, 'Controller must redirect non-DORA-obligated tenant');
    }

    #[Test]
    public function obligatedTenantWouldNotTriggerControllerRedirect(): void
    {
        // Documents the controller gate logic: DORA-obligated tenants pass through.
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);

        $shouldRedirect = !$tenant->isDoraObligated();

        self::assertFalse($shouldRedirect, 'Obligated tenant must NOT be redirected');
    }
}
