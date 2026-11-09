<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 11 (module gate): framework fields and routes are gated by their module.
 *
 * @extends GateRuleTestCase<ModuleGateRule>
 */
final class ModuleGateRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new ModuleGateRule();
    }

    #[Test]
    public function formFieldsSitInTheirModuleGate(): void
    {
        $this->assertFixture('src/Form/GateFixture/ModuleFieldsType.php');
    }

    #[Test]
    public function routesOfAnOptionalModuleAreGated(): void
    {
        $this->assertFixture('src/Controller/GateFixture/DpiaController.php');
    }
}
