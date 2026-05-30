<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\PolicyParameterCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyParameterCatalogTest extends TestCase
{
    private function catalog(): PolicyParameterCatalog
    {
        return new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');
    }

    #[Test]
    public function it_loads_mfa_scope_from_yaml(): void
    {
        $def = $this->catalog()->get('mfa_scope');

        self::assertSame('mfa_scope', $def->key);
        self::assertSame('privileged_external', $def->default);
        self::assertContains('all', $def->allowed);
    }

    #[Test]
    public function it_lists_all_known_keys(): void
    {
        self::assertContains('mfa_scope', $this->catalog()->keys());
    }

    #[Test]
    public function it_throws_for_unknown_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog()->get('does_not_exist');
    }
}
