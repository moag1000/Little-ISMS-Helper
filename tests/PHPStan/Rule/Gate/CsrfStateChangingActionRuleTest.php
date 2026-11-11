<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 65: the mutations of the T3 counter-check are red, the accepted spellings green.
 *
 * @extends GateRuleTestCase<CsrfStateChangingActionRule>
 */
final class CsrfStateChangingActionRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new CsrfStateChangingActionRule();
    }

    #[Test]
    public function stateChangingActionsCheckTheTokenAndGetActionsDoNotWrite(): void
    {
        $this->assertFixture('src/Controller/GateFixture/CsrfController.php');
    }

    #[Test]
    public function aFormTypeDoesNotSwitchCsrfOff(): void
    {
        $this->assertFixture('src/Form/GateFixture/CsrfOffType.php');
    }
}
