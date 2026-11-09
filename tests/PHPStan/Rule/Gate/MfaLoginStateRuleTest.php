<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 91: a hand-made login without MfaSessionState::begin() is red in every spelling.
 *
 * @extends GateRuleTestCase<MfaLoginStateRule>
 */
final class MfaLoginStateRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new MfaLoginStateRule();
    }

    #[Test]
    public function handMadeLoginsNeedTheMfaState(): void
    {
        $this->assertFixture('src/Security/GateFixture/HandMadeLogin.php');
    }
}
