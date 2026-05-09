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

    /**
     * Helper: pull the warning DTO whose `rule` matches; null if absent.
     *
     * @param list<array<string, mixed>> $warnings
     * @return array<string, mixed>|null
     */
    private static function warningFor(array $warnings, string $rule): ?array
    {
        foreach ($warnings as $w) {
            if (($w['rule'] ?? null) === $rule) {
                return $w;
            }
        }
        return null;
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

        // Wish #6 — click-jump payload: jump to operational_baselines (RPO lives there).
        $w = self::warningFor($warnings, CrossStepConsistencyValidator::RULE_CONSERVATIVE_RPO);
        self::assertNotNull($w);
        self::assertSame(WizardStepKeys::STEP_OPERATIONAL_BASELINES, $w['target_step']);

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

        // Wish #6 — click-jump payload: patch SLA lives in operational_baselines.
        $w = self::warningFor($warnings, CrossStepConsistencyValidator::RULE_AGGRESSIVE_PATCH);
        self::assertNotNull($w);
        self::assertSame(WizardStepKeys::STEP_OPERATIONAL_BASELINES, $w['target_step']);
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

        // Wish #6 — click-jump payload: DORA RPO lives in operational_baselines.
        $w = self::warningFor($warnings, CrossStepConsistencyValidator::RULE_DORA_RPO);
        self::assertNotNull($w);
        self::assertSame(WizardStepKeys::STEP_OPERATIONAL_BASELINES, $w['target_step']);

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

        // Wish #6 — click-jump payload: continuity RTO lives in operational_baselines.
        $w = self::warningFor($warnings, CrossStepConsistencyValidator::RULE_BCM_RTO);
        self::assertNotNull($w);
        self::assertSame(WizardStepKeys::STEP_OPERATIONAL_BASELINES, $w['target_step']);
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

        // Wish #6 — click-jump payload: DPO is assigned in Step 3 Roles
        // (operational_baselines slot only carries the OpBaselines fallback).
        $w = self::warningFor($warnings, CrossStepConsistencyValidator::RULE_SIGNIFICANT_DPO);
        self::assertNotNull($w);
        self::assertSame(WizardStepKeys::STEP_ROLES, $w['target_step']);

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

    /**
     * Wish #6 — every emitted warning DTO MUST carry a non-empty
     * `target_step` field so the Step 7 click-jump UI never has a
     * dangling button.
     */
    #[Test]
    public function testEveryWarningCarriesTargetStep(): void
    {
        $validator = new CrossStepConsistencyValidator();
        // Run that triggers every rule simultaneously.
        $run = $this->makeRun(
            [
                WizardStepKeys::STEP_RISK_CLASSIFICATION => ['risk_appetite_tier' => 1],
                WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                    'backup_rpo_hours' => 48,
                    'patch_sla_hours' => ['critical' => 2],
                    'continuity_rto_hours' => ['high' => 48],
                    'dora' => ['is_significant' => true],
                ],
            ],
            ['iso27001', 'dora', 'bcm'],
        );

        $warnings = $validator->validate($run);
        self::assertNotEmpty($warnings);

        $allowedSteps = [
            WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            WizardStepKeys::STEP_ROLES,
        ];
        foreach ($warnings as $w) {
            self::assertArrayHasKey('target_step', $w, sprintf('Warning %s missing target_step', $w['rule'] ?? '?'));
            self::assertNotSame('', $w['target_step']);
            self::assertContains($w['target_step'], $allowedSteps);
        }
    }
}
