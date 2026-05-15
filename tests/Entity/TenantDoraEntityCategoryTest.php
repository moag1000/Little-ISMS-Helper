<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Tenant::doraEntityCategory field and isDoraObligated() logic.
 *
 * Covers: default value, constants, setter/getter, isDoraObligated() for all 3 values.
 */
final class TenantDoraEntityCategoryTest extends TestCase
{
    #[Test]
    public function defaultCategoryIsNone(): void
    {
        $tenant = new Tenant();
        self::assertSame(Tenant::DORA_NONE, $tenant->getDoraEntityCategory());
    }

    #[Test]
    public function constantsHaveCorrectStringValues(): void
    {
        self::assertSame('none', Tenant::DORA_NONE);
        self::assertSame('financial_entity', Tenant::DORA_FINANCIAL_ENTITY);
        self::assertSame('critical_ict_third_party', Tenant::DORA_CRITICAL_ICT_THIRD_PARTY);
    }

    #[Test]
    public function isDoraObligatedReturnsFalseForNone(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        self::assertFalse($tenant->isDoraObligated());
    }

    #[Test]
    public function isDoraObligatedReturnsTrueForFinancialEntity(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);

        self::assertTrue($tenant->isDoraObligated());
    }

    #[Test]
    public function isDoraObligatedReturnsTrueForCriticalIctThirdParty(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_CRITICAL_ICT_THIRD_PARTY);

        self::assertTrue($tenant->isDoraObligated());
    }

    #[Test]
    public function setterReturnsSelf(): void
    {
        $tenant = new Tenant();
        $result = $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);

        self::assertSame($tenant, $result);
    }

    #[Test]
    public function setterChangesValueFromDefaultToObligated(): void
    {
        $tenant = new Tenant();
        self::assertFalse($tenant->isDoraObligated(), 'Default must be not-obligated');

        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        self::assertTrue($tenant->isDoraObligated(), 'Must be obligated after setting financial_entity');
    }

    #[Test]
    public function categoryCanBeResetToNone(): void
    {
        $tenant = new Tenant();
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        self::assertTrue($tenant->isDoraObligated());

        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);
        self::assertFalse($tenant->isDoraObligated());
    }
}
