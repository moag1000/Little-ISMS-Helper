<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\Step\OperationalBaselinesStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Junior-Implementer-Persona feedback (May 2026) — Wish #3.
 *
 * Verifies the DORA-Significance Self-Check: three guided questions
 * (significance_q1/q2/q3) drive the server-derived `is_significant`
 * boolean per DORA Art. 16 + lex-RTS threshold. Threshold: ≥ 2 of 3
 * yes answers → significant entity.
 */
final class OperationalBaselinesStepDoraSignificanceTest extends TestCase
{
    private function makeDoraRun(): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted(['iso27001', 'dora']);
        $run->setStep(WizardStepKeys::STEP_OPERATIONAL_BASELINES);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs([]);
        return $run;
    }

    /**
     * @return array{0: array<string, mixed>}
     */
    private function baseRequiredInput(array $doraExtra = []): array
    {
        return [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'dora' => array_merge([
                'entity_type' => 'kreditinstitut',
                'competent_authority' => 'BaFin',
            ], $doraExtra),
        ];
    }

    #[Test]
    public function testTwoYesAnswersDeriveSignificantTrue(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeDoraRun();

        $result = $step->validate($run, $this->baseRequiredInput([
            'significance_q1' => '1',
            'significance_q2' => '1',
            'significance_q3' => '0',
        ]));

        self::assertSame([], $result['errors']);
        self::assertNotNull($result['normalised_input']['dora']);
        self::assertTrue($result['normalised_input']['dora']['is_significant']);
        self::assertTrue($result['normalised_input']['dora']['significance_q1']);
        self::assertTrue($result['normalised_input']['dora']['significance_q2']);
        self::assertFalse($result['normalised_input']['dora']['significance_q3']);
        self::assertSame('significant', $result['normalised_input']['dora']['significance']);
    }

    #[Test]
    public function testThreeYesAnswersDeriveSignificantTrue(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeDoraRun();

        $result = $step->validate($run, $this->baseRequiredInput([
            'significance_q1' => 'yes',
            'significance_q2' => 'true',
            'significance_q3' => 'on',
        ]));

        self::assertSame([], $result['errors']);
        self::assertTrue($result['normalised_input']['dora']['is_significant']);
        self::assertSame('significant', $result['normalised_input']['dora']['significance']);
    }

    #[Test]
    public function testZeroOrOneYesDerivesSignificantFalse(): void
    {
        $step = new OperationalBaselinesStep();

        // One yes, two no → standard.
        $resultOneYes = $step->validate($this->makeDoraRun(), $this->baseRequiredInput([
            'significance_q1' => '1',
            'significance_q2' => '0',
            'significance_q3' => '0',
        ]));
        self::assertSame([], $resultOneYes['errors']);
        self::assertFalse($resultOneYes['normalised_input']['dora']['is_significant']);
        self::assertSame('standard', $resultOneYes['normalised_input']['dora']['significance']);

        // All three no → standard.
        $resultZeroYes = $step->validate($this->makeDoraRun(), $this->baseRequiredInput([
            'significance_q1' => '0',
            'significance_q2' => '0',
            'significance_q3' => '0',
        ]));
        self::assertSame([], $resultZeroYes['errors']);
        self::assertFalse($resultZeroYes['normalised_input']['dora']['is_significant']);
    }

    #[Test]
    public function testEmptyAnswersLeaveBoolFalse(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeDoraRun();

        // No Self-Check fields submitted at all — the legacy
        // is_significant boolean (default false) survives untouched
        // for backwards-compat with sandbox / API callers.
        $result = $step->validate($run, $this->baseRequiredInput());

        self::assertSame([], $result['errors']);
        self::assertFalse($result['normalised_input']['dora']['is_significant']);
        self::assertSame('standard', $result['normalised_input']['dora']['significance']);
    }
}
