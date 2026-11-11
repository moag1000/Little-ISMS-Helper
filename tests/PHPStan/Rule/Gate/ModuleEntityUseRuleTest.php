<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 106: a controller that touches the records of an optional module is gated by that module.
 *
 * @extends GateRuleTestCase<ModuleEntityUseRule>
 */
final class ModuleEntityUseRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new ModuleEntityUseRule(['DataBreach' => 'privacy']);
    }

    #[Test]
    public function recordsOfAnOptionalModuleAreReadOnlyBehindItsGate(): void
    {
        $this->assertFixture('src/Controller/GateFixture/EntityUseController.php');
    }

    #[Test]
    public function theOwnerTableSkipsSharedAndRequiredRecords(): void
    {
        $owners = ModuleEntityUseRule::ownersFromModulesYaml(dirname(__DIR__, 4) . '/config/modules.yaml');

        self::assertSame('privacy', $owners['DataBreach']);
        self::assertSame('compliance', $owners['ComplianceFramework']);
        // Listed by several modules / by a module that cannot be switched off / master data only referred to.
        foreach (['Supplier', 'Risk', 'Asset', 'Incident', 'Control', 'InternalAudit', 'ManagementReview', 'Person', 'Location'] as $entity) {
            self::assertArrayNotHasKey($entity, $owners);
        }
    }
}
