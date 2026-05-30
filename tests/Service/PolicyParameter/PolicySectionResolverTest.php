<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\ParameterDefinition;
use App\Service\PolicyParameter\PolicySectionResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicySectionResolverTest extends TestCase
{
    private function def(array $slot): ParameterDefinition
    {
        return ParameterDefinition::fromArray('p', ['type' => 'enum', 'default' => 'x', 'template_slot' => $slot]);
    }

    #[Test]
    public function not_condition_is_inactive_when_value_equals(): void
    {
        $r = new PolicySectionResolver();
        $def = $this->def(['interpolate' => 'k', 'section_if' => ['not' => 'none']]);

        self::assertFalse($r->isActive($def, 'none'));
        self::assertTrue($r->isActive($def, 'all'));
    }

    #[Test]
    public function exists_condition_needs_non_null(): void
    {
        $r = new PolicySectionResolver();
        $def = $this->def(['interpolate' => 'k', 'section_if' => ['exists' => true]]);

        self::assertTrue($r->isActive($def, '4tier'));
        self::assertFalse($r->isActive($def, null));
    }

    #[Test]
    public function no_section_if_is_always_active(): void
    {
        $r = new PolicySectionResolver();
        $def = $this->def(['interpolate' => 'k']);

        self::assertTrue($r->isActive($def, 'anything'));
        self::assertTrue($r->isActive($def, null));
    }
}
