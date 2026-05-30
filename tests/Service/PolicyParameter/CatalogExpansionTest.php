<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\PolicyParameterCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogExpansionTest extends TestCase
{
    private function catalog(): PolicyParameterCatalog
    {
        return new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');
    }

    #[Test]
    public function it_loads_the_three_new_params(): void
    {
        $c = $this->catalog();

        self::assertSame('single_ciso', $c->get('approval_model')->default);
        self::assertSame(180, $c->get('log_retention_days')->default);
        self::assertSame('3tier', $c->get('classification_scheme')->default);
    }

    #[Test]
    public function approval_model_has_dora_regulatory_min(): void
    {
        $def = $this->catalog()->get('approval_model');

        self::assertSame('dual_signoff', $def->frameworkMin('dora'));
        self::assertSame('regulatory', $def->frameworkAuthority('dora'));
    }
}
