<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\DoraSettingsCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Exception\InvalidArgument\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-A — DoraSettingsCollector unit tests.
 *
 * Verifies the dora.* variable-namespace contract used by the W4-A
 * §6 Step 5b block (architecture §6 Step 5) and consumed by the DORA
 * extension translation key during DocumentGenerator render.
 */
final class DoraSettingsCollectorTest extends TestCase
{
    private function makeRun(array $doraBlock = []): WizardRun
    {
        $run = new WizardRun();
        $run->setInputs([
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'dora' => $doraBlock,
            ],
        ]);
        return $run;
    }

    #[Test]
    public function testCollectsAllDoraVariables(): void
    {
        $collector = new DoraSettingsCollector();
        $run = $this->makeRun([
            'entity_type' => 'credit_institution',
            'significance' => true,
            'competent_authority' => 'BaFin',
            'concentration_thresholds' => [
                'cif_count_pct' => 30,
                'revenue_share_pct' => 25,
            ],
        ]);

        $vars = $collector->collectFor($run);

        self::assertSame('credit_institution', $vars['dora.entity_type']);
        self::assertTrue($vars['dora.significance']);
        self::assertSame('BaFin', $vars['dora.competent_authority']);
        self::assertIsString($vars['dora.concentration_thresholds']);
        self::assertStringContainsString('cif_count_pct', $vars['dora.concentration_thresholds']);
        self::assertStringContainsString('25', $vars['dora.concentration_thresholds']);
        self::assertSame(DoraSettingsCollector::DORA_VALIDITY_FROM, $vars['dora.validity_from']);
    }

    #[Test]
    public function testValidityFromConstant(): void
    {
        $collector = new DoraSettingsCollector();
        $run = $this->makeRun();

        $vars = $collector->collectFor($run);

        // DORA Art. 64: regulation applicable from 2025-01-17.
        // The constant is regulatory and not tenant-overridable.
        self::assertSame('2025-01-17', $vars['dora.validity_from']);
        self::assertSame(
            '2025-01-17',
            DoraSettingsCollector::DORA_VALIDITY_FROM,
            'DORA_VALIDITY_FROM is the regulatory effective date — must NOT change',
        );

        // Even with explicit override attempts the validity date remains.
        $hostileRun = $this->makeRun([
            'validity_from' => '2999-01-01',
            'dora_validity_from' => '2999-01-01',
        ]);
        $hostileVars = $collector->collectFor($hostileRun);
        self::assertSame('2025-01-17', $hostileVars['dora.validity_from']);
    }

    #[Test]
    public function testEmptyInputReturnsDefaults(): void
    {
        $collector = new DoraSettingsCollector();

        // Empty inputs → all dora.* vars resolve to null, with the sole
        // exception of dora.validity_from (regulatory constant). Keys
        // are still present so substitution markers render as blank
        // (architecture §11.2 invariant — no leftover `{{ }}`).
        $run = $this->makeRun([]);
        $vars = $collector->collectFor($run);

        self::assertNull($vars['dora.entity_type']);
        self::assertNull($vars['dora.significance']);
        self::assertNull($vars['dora.competent_authority']);
        self::assertNull($vars['dora.concentration_thresholds']);
        self::assertSame('2025-01-17', $vars['dora.validity_from']);

        // No-DORA-block-at-all path — same behaviour.
        $bareRun = new WizardRun();
        $bareRun->setInputs([]);
        $bareVars = $collector->collectFor($bareRun);
        self::assertNull($bareVars['dora.entity_type']);
        self::assertSame('2025-01-17', $bareVars['dora.validity_from']);
    }

    #[Test]
    public function testInvalidEntityTypeRaises(): void
    {
        $collector = new DoraSettingsCollector();
        $run = $this->makeRun([
            'entity_type' => 'shadow_bank_pretending_to_be_a_unicorn',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/entity_type/');

        $collector->collectFor($run);
    }
}
