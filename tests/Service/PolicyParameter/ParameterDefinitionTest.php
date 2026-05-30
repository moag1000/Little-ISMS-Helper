<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\ParameterDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParameterDefinitionTest extends TestCase
{
    #[Test]
    public function it_exposes_key_default_and_allowed_values(): void
    {
        $def = ParameterDefinition::fromArray('mfa_scope', [
            'category' => 'access_control',
            'type' => 'enum',
            'allowed' => ['all', 'none'],
            'default' => 'all',
            'wizard_step' => 'governance_controls',
        ]);

        self::assertSame('mfa_scope', $def->key);
        self::assertSame('all', $def->default);
        self::assertSame(['all', 'none'], $def->allowed);
        self::assertSame('governance_controls', $def->wizardStep);
    }

    #[Test]
    public function it_returns_framework_min_when_present(): void
    {
        $def = ParameterDefinition::fromArray('mfa_scope', [
            'type' => 'enum',
            'default' => 'privileged_external',
            'framework_constraints' => [
                'dora' => ['min' => 'all', 'authority' => 'regulatory', 'source' => 'DORA Art. 9(3)'],
            ],
        ]);

        self::assertSame('all', $def->frameworkMin('dora'));
        self::assertSame('regulatory', $def->frameworkAuthority('dora'));
        self::assertNull($def->frameworkMin('nis2'));
    }
}
