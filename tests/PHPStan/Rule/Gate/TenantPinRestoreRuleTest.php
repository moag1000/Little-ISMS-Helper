<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 97 (T4): a pin without a restoring finally is red in every spelling of the counter-check.
 *
 * @extends GateRuleTestCase<TenantPinRestoreRule>
 */
final class TenantPinRestoreRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new TenantPinRestoreRule();
    }

    #[Test]
    public function aPinnedOrganisationIsPutBack(): void
    {
        $this->assertFixture('src/Service/GateFixture/TenantPins.php');
    }
}
