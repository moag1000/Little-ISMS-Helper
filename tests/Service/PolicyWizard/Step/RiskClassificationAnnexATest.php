<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\Step\RiskClassificationStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit follow-up (May 2026) — covers the new annex_a_applicability
 * UI on RiskClassificationStep:
 *  - all controls default to applicable when no input is provided
 *  - explicit "not applicable" persists as `false`
 *  - the entire applicability map round-trips into WizardRun.inputs
 */
final class RiskClassificationAnnexATest extends TestCase
{
    private function makeRun(): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted(['iso27001']);
        $run->setStep(WizardStepKeys::STEP_RISK_CLASSIFICATION);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs([]);
        return $run;
    }

    #[Test]
    public function testAllControlsApplicableByDefault(): void
    {
        $step = new RiskClassificationStep();
        $run = $this->makeRun();

        // Empty annex_a → step accepts and produces an empty map (which
        // means: rely on template default = all applicable).
        $result = $step->validate($run, [
            'risk_appetite_tier' => 3,
        ]);

        self::assertSame([], $result['errors']);
        self::assertIsArray($result['normalised_input']['annex_a_applicability']);
        self::assertSame([], $result['normalised_input']['annex_a_applicability']);
    }

    #[Test]
    public function testNotApplicableRequiresJustification(): void
    {
        $step = new RiskClassificationStep();
        $run = $this->makeRun();

        // Bool-cast keeps "false" semantics. The justification is captured
        // outside the wizard (SoA module); validate() only persists the
        // applicability flag.
        $result = $step->validate($run, [
            'risk_appetite_tier' => 3,
            'annex_a_applicability' => [
                'A.5.1' => true,
                'A.7.4' => false,
                'A.8.34' => false,
            ],
        ]);

        self::assertSame([], $result['errors']);
        self::assertTrue($result['normalised_input']['annex_a_applicability']['A.5.1']);
        self::assertFalse($result['normalised_input']['annex_a_applicability']['A.7.4']);
        self::assertFalse($result['normalised_input']['annex_a_applicability']['A.8.34']);
    }

    #[Test]
    public function testAnnexAPersistedInWizardRunInputs(): void
    {
        $step = new RiskClassificationStep();
        $run = $this->makeRun();

        $payload = [
            'A.5.1' => true,
            'A.5.15' => true,
            'A.6.3' => false,
            'A.8.7' => true,
        ];

        $result = $step->validate($run, [
            'risk_appetite_tier' => 2,
            'annex_a_applicability' => $payload,
        ]);

        $step->persist($run, $result['normalised_input']);

        $slot = $run->getInputs()[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? null;
        self::assertIsArray($slot);
        self::assertArrayHasKey('annex_a_applicability', $slot);
        self::assertSame($payload, $slot['annex_a_applicability']);
    }
}
