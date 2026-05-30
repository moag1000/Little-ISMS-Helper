<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\PolicyBaselineDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyBaselineDefinitionTest extends TestCase
{
    #[Test]
    public function it_exposes_sector_frameworks_topics_regulator(): void
    {
        $b = PolicyBaselineDefinition::fromArray([
            'sector' => 'finance_bafin',
            'frameworks' => ['iso27001', 'dora'],
            'mandatory_topics' => ['third_party_register'],
            'regulator' => 'bafin',
            'flags' => ['has_works_council' => true],
            'parameter_presets' => [
                'mfa_scope' => ['value' => 'all', 'authority' => 'regulatory', 'source' => 'DORA Art. 9(3)'],
            ],
        ]);

        self::assertSame('finance_bafin', $b->sector);
        self::assertSame(['iso27001', 'dora'], $b->frameworks);
        self::assertSame(['third_party_register'], $b->mandatoryTopics);
        self::assertSame('bafin', $b->regulator);
        self::assertTrue($b->flags['has_works_council']);
    }

    #[Test]
    public function it_returns_preset_values_and_authority(): void
    {
        $b = PolicyBaselineDefinition::fromArray([
            'sector' => 'x',
            'parameter_presets' => [
                'mfa_scope' => ['value' => 'all', 'authority' => 'regulatory', 'source' => 'DORA'],
            ],
        ]);

        self::assertSame(['mfa_scope' => 'all'], $b->presetValues());
        self::assertSame('regulatory', $b->presetAuthority('mfa_scope'));
        self::assertNull($b->presetAuthority('unknown'));
    }
}
