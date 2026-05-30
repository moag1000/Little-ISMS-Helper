<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\PolicyBaselineCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyBaselineCatalogTest extends TestCase
{
    private function catalog(): PolicyBaselineCatalog
    {
        return new PolicyBaselineCatalog(\dirname(__DIR__, 3) . '/config/policy_baselines');
    }

    #[Test]
    public function it_loads_all_four_sectors(): void
    {
        $sectors = $this->catalog()->sectors();

        self::assertContains('mittelstand_generic', $sectors);
        self::assertContains('automotive_supplier', $sectors);
        self::assertContains('finance_bafin', $sectors);
        self::assertContains('kritis_utility', $sectors);
    }

    #[Test]
    public function finance_baseline_has_dora_and_dual_signoff(): void
    {
        $b = $this->catalog()->get('finance_bafin');

        self::assertContains('dora', $b->frameworks);
        self::assertSame('dual_signoff', $b->presetValues()['approval_model']);
        self::assertSame('bafin', $b->regulator);
    }

    #[Test]
    public function it_throws_for_unknown_sector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog()->get('atlantis');
    }
}
