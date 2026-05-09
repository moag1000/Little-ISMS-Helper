<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\CrossStepConsistencyValidator;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit follow-up (May 2026) — exercises each cross-step
 * consistency rule. The validator emits NON-blocking warnings; tests
 * assert the rule fires (or stays silent) under the documented
 * preconditions.
 */
final class CrossStepConsistencyValidatorTest extends TestCase
{
    private function makeRun(array $inputs, array $standards = ['iso27001']): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted($standards);
        $run->setInputs($inputs);
        return $run;
    }

    #[Test]
    public function testRuleConservativeRpoFires(): void
    {
        $validator = new CrossStepConsistencyValidator();
        $run = $this->makeRun([
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 1],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['backup_rpo_hours' => 24],
        ]);

        $warnings = $validator->validate($run);
        $rules = array_column($warnings, 'rule');
        self::assertContains(CrossStepConsistencyValidator::RULE_CONSERVATIVE_RPO, $rules);

        // Same tier with RPO ≤ 12 → no warning.
        $runOk = $this->makeRun([
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 1],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['backup_rpo_hours' => 6],
        ]);
        $rulesOk = array_column($validator->validate($runOk), 'rule');
        self::assertNotContains(CrossStepConsistencyValidator::RULE_CONSERVATIVE_RPO, $rulesOk);
    }

    #[Test]
    public function testRuleAggressivePatchFires(): void
    {
        $validator = new CrossStepConsistencyValidator();
        $run = $this->makeRun([
            WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 5],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'patch_sla_hours' => ['critical' => 2],
            ],
        ]);

        $warnings = $validator->validate($run);
        $rules = array_column($warnings, 'rule');
        self::assertContains(CrossStepConsistencyValidator::RULE_AGGRESSIVE_PATCH, $rules);
    }

    #[Test]
    public function testRuleDoraRpoFires(): void
    {
        $validator = new CrossStepConsistencyValidator();
        $run = $this->makeRun(
            [
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['backup_rpo_hours' => 48],
            ],
            ['iso27001', 'dora'],
        );

        $warnings = $validator->validate($run);
        $rules = array_column($warnings, 'rule');
        self::assertContains(CrossStepConsistencyValidator::RULE_DORA_RPO, $rules);

        // Without DORA in scope the rule must not fire.
        $runNoDora = $this->makeRun(
            [
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['backup_rpo_hours' => 48],
            ],
            ['iso27001'],
        );
        $rulesNoDora = array_column($validator->validate($runNoDora), 'rule');
        self::assertNotContains(CrossStepConsistencyValidator::RULE_DORA_RPO, $rulesNoDora);
    }

    #[Test]
    public function testRuleBcmRtoFires(): void
    {
        $validator = new CrossStepConsistencyValidator();
        $run = $this->makeRun(
            [
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                    'continuity_rto_hours' => ['high' => 48],
                ],
            ],
            ['iso27001', 'bcm'],
        );

        $warnings = $validator->validate($run);
        $rules = array_column($warnings, 'rule');
        self::assertContains(CrossStepConsistencyValidator::RULE_BCM_RTO, $rules);
    }

    #[Test]
    public function testRuleSignificantNoDpoFires(): void
    {
        $validator = new CrossStepConsistencyValidator();
        $run = $this->makeRun(
            [
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                    'dora' => ['is_significant' => true],
                ],
            ],
            ['iso27001', 'dora'],
        );

        $warnings = $validator->validate($run);
        $rules = array_column($warnings, 'rule');
        self::assertContains(CrossStepConsistencyValidator::RULE_SIGNIFICANT_DPO, $rules);

        // With a DPO assigned (RolesStep slot) the rule must NOT fire.
        $runWithDpo = $this->makeRun(
            [
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                    'dora' => ['is_significant' => true],
                ],
                WizardStepKeys::STEP_ROLES => [
                    'roles' => ['dpo' => 42],
                ],
            ],
            ['iso27001', 'dora'],
        );
        $rulesWithDpo = array_column($validator->validate($runWithDpo), 'rule');
        self::assertNotContains(CrossStepConsistencyValidator::RULE_SIGNIFICANT_DPO, $rulesWithDpo);

        // DPO from OpBaselines slot also satisfies the rule.
        $runWithOpDpo = $this->makeRun(
            [
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                    'dora' => ['is_significant' => true],
                    'dpo_user_id' => 17,
                ],
            ],
            ['iso27001', 'dora'],
        );
        $rulesWithOpDpo = array_column($validator->validate($runWithOpDpo), 'rule');
        self::assertNotContains(CrossStepConsistencyValidator::RULE_SIGNIFICANT_DPO, $rulesWithOpDpo);
    }
}
