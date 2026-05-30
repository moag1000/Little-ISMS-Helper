<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\FrameworkConstraintChecker;
use App\Service\PolicyParameter\ParameterDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameworkConstraintCheckerTest extends TestCase
{
    private function mfaDef(): ParameterDefinition
    {
        return ParameterDefinition::fromArray('mfa_scope', [
            'type' => 'enum',
            'allowed' => ['none', 'privileged_only', 'privileged_external', 'all'],
            'default' => 'privileged_external',
            'framework_constraints' => [
                'dora' => ['min' => 'all', 'authority' => 'regulatory', 'source' => 'DORA Art. 9(3)'],
                'nis2' => ['min' => 'privileged_external', 'authority' => 'regulatory', 'source' => 'NIS2'],
            ],
        ]);
    }

    private function intDef(): ParameterDefinition
    {
        return ParameterDefinition::fromArray('log_retention_days', [
            'type' => 'int',
            'default' => 180,
            'framework_constraints' => [
                'dora' => ['min' => 365, 'authority' => 'benchmark', 'source' => 'x'],
            ],
        ]);
    }

    #[Test]
    public function enum_value_below_min_is_a_violation(): void
    {
        $v = (new FrameworkConstraintChecker())->check($this->mfaDef(), 'privileged_external', 'dora');

        self::assertNotNull($v);
        self::assertSame('mfa_scope', $v->paramKey);
        self::assertSame('all', $v->requiredMin);
        self::assertSame('privileged_external', $v->actualValue);
        self::assertSame('regulatory', $v->authority);
        self::assertTrue($v->isBlocking());
    }

    #[Test]
    public function enum_value_meeting_min_is_ok(): void
    {
        $checker = new FrameworkConstraintChecker();

        self::assertNull($checker->check($this->mfaDef(), 'all', 'dora'));
        self::assertNull($checker->check($this->mfaDef(), 'privileged_external', 'nis2'));
        self::assertNull($checker->check($this->mfaDef(), 'all', 'gdpr'));
    }

    #[Test]
    public function int_value_below_min_is_a_non_blocking_violation(): void
    {
        $v = (new FrameworkConstraintChecker())->check($this->intDef(), 180, 'dora');

        self::assertNotNull($v);
        self::assertSame(365, $v->requiredMin);
        self::assertFalse($v->isBlocking());
    }

    #[Test]
    public function int_value_meeting_min_is_ok(): void
    {
        self::assertNull((new FrameworkConstraintChecker())->check($this->intDef(), 400, 'dora'));
    }
}
