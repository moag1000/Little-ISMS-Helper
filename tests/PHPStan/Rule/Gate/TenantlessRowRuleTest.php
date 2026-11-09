<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 72: new tenant-owned rows get a tenant, "own OR global" queries need SharedTenantRowsInterface.
 *
 * @extends GateRuleTestCase<TenantlessRowRule>
 */
final class TenantlessRowRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new TenantlessRowRule(self::createReflectionProvider());
    }

    #[Test]
    public function newRowsGetATenant(): void
    {
        $this->assertFixture('src/Service/GateFixture/TenantRowService.php');
    }

    #[Test]
    public function ownOrGlobalQueriesInEverySpelling(): void
    {
        $this->assertFixture('src/Repository/GateFixture/OwnOrGlobalRepository.php');
    }
}
