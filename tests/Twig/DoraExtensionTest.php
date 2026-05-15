<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Tenant;
use App\Service\TenantContext;
use App\Twig\DoraExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoraExtension::isDoraObligated() Twig function.
 *
 * Covers: no-tenant context (safe default false), tenant with DORA_NONE (false),
 * tenant with DORA_FINANCIAL_ENTITY (true), tenant with DORA_CRITICAL_ICT_THIRD_PARTY (true).
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraExtensionTest extends TestCase
{
    #[Test]
    public function returnsFalseWhenNoTenantInContext(): void
    {
        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);

        $extension = new DoraExtension($context);

        self::assertFalse($extension->isDoraObligated());
    }

    #[Test]
    public function returnsFalseWhenTenantCategoryIsNone(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);

        $extension = new DoraExtension($context);

        self::assertFalse($extension->isDoraObligated());
    }

    #[Test]
    public function returnsTrueWhenTenantIsFinancialEntity(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);

        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);

        $extension = new DoraExtension($context);

        self::assertTrue($extension->isDoraObligated());
    }

    #[Test]
    public function returnsTrueWhenTenantIsCriticalIctThirdParty(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_CRITICAL_ICT_THIRD_PARTY);

        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);

        $extension = new DoraExtension($context);

        self::assertTrue($extension->isDoraObligated());
    }
}
