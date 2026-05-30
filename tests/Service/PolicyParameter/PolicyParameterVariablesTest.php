<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\PolicyParameterCatalog;
use App\Service\PolicyParameter\PolicyParameterVariables;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyParameterVariablesTest extends TestCase
{
    private function variables(): PolicyParameterVariables
    {
        return new PolicyParameterVariables(
            new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters')
        );
    }

    #[Test]
    public function it_maps_interpolate_keys_to_resolved_values(): void
    {
        $vars = $this->variables()->build(['mfa_scope' => 'all', 'approval_model' => 'dual_signoff']);

        self::assertSame('all', $vars['policy.access.mfa_value']);
        self::assertSame('dual_signoff', $vars['policy.governance.approval']);
    }

    #[Test]
    public function params_without_interpolate_slot_are_skipped(): void
    {
        $vars = $this->variables()->build(['mfa_scope' => 'all']);

        self::assertArrayNotHasKey('mfa_scope', $vars);
        self::assertArrayHasKey('policy.access.mfa_value', $vars);
    }

    #[Test]
    public function missing_resolved_value_falls_back_to_catalog_default(): void
    {
        $vars = $this->variables()->build([]);

        self::assertSame('privileged_external', $vars['policy.access.mfa_value']);
    }
}
