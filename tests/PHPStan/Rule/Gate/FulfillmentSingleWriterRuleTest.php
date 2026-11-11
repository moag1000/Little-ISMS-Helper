<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 85, type part (T4): a fulfilment writer is found by the receiver's type, not its name.
 *
 * @extends GateRuleTestCase<FulfillmentSingleWriterRule>
 */
final class FulfillmentSingleWriterRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new FulfillmentSingleWriterRule();
    }

    #[Test]
    public function fulfilmentValuesHaveOneWriter(): void
    {
        $this->assertFixture('src/Service/GateFixture/FulfillmentWriters.php');
    }
}
