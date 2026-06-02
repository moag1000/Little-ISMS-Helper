<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\TenantPolicySettingRepository;
use App\Repository\UserRepository;
use App\Service\PolicyWizard\Step\OperationalBaselinesStep;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end round-trip for operational-baseline rendering variables.
 *
 * Exercises the real integration seam that the two services share but
 * never test together: the keys {@see OperationalBaselinesStep::validate()}
 * writes into `normalised_input` MUST match the keys
 * {@see VariableCollector::collectFor()} reads back. A rename on one side
 * silently drops a baseline from every generated policy — this test fails
 * loudly instead.
 *
 * Covers all 10 baselines, including the two (patch-SLA, continuity-RTO)
 * that were captured + validated but never exposed as render vars before.
 */
#[AllowMockObjectsWithoutExpectations]
final class OperationalBaselineVarRoundTripTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn(7);
        $stub->method('getLegalName')->willReturn('MyCompany GmbH');
        $stub->method('getName')->willReturn('MyCompany');
        return $stub;
    }

    private function makeCollector(): VariableCollector
    {
        return new VariableCollector(
            $this->createStub(TenantPolicySettingRepository::class),
            $this->createStub(UserRepository::class),
        );
    }

    #[Test]
    public function rawFormInputFlowsThroughValidateIntoEveryRenderVar(): void
    {
        // BCM in scope so the continuity-RTO branch is active.
        $run = new WizardRun();
        $run->setTenant($this->makeTenant());
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStandardsAdopted(['iso27001', 'bcm']);

        // Raw, template-rendered field set exactly as the HTML form posts it.
        $rawInput = [
            'crypto_allowlist' => ['AES-256', 'RSA-2048'],
            'backup_rpo_hours' => '4',
            'patch_sla_hours' => ['critical' => '4', 'high' => '24', 'medium' => '168'],
            'continuity_rto_hours' => ['mission_critical' => '2', 'important' => '8'],
            'access_review_cadence_months' => '6',
            'mfa_scope' => 'privileged_only',
            'logging_retention_months' => ['security' => '12', 'app' => '3', 'system' => '3'],
            'vuln_scan_cadence' => ['external_cadence' => 'monthly', 'internal_cadence' => 'weekly'],
            'working_modes' => ['office', 'hybrid'],
            'cloud_onprem_mix_pct' => '50',
        ];

        // Step 1 — validate + normalise (the controller's save path).
        $result = (new OperationalBaselinesStep())->validate($run, $rawInput);
        self::assertSame([], $result['errors'], 'valid baseline input must not raise errors');

        // Step 2 — persist normalised input back onto the run (what the
        // controller stores under the operational-baselines slot).
        $run->setInputs([
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => $result['normalised_input'],
        ]);

        // Step 3 — collect render variables (the generation path).
        $vars = $this->makeCollector()->collectFor($run);

        // ── All 10 baselines must resolve to a render var ──────────────
        self::assertSame('AES-256, RSA-2048', $vars['crypto.algorithms']);
        self::assertSame(4, $vars['backup.rpo_hours']);
        self::assertSame(4, $vars['patch.sla_critical_hours']);
        self::assertSame(24, $vars['patch.sla_high_hours']);
        self::assertSame(168, $vars['patch.sla_medium_hours']);
        self::assertSame('mission_critical: 2h, important: 8h', $vars['continuity.rto_summary']);
        self::assertSame(6, $vars['access.review_cadence_months']);
        self::assertSame('privileged_only', $vars['mfa.scope']);
        self::assertSame(12, $vars['logging.retention_security_months']);
        self::assertSame(3, $vars['logging.retention_app_months']);
        self::assertSame(3, $vars['logging.retention_system_months']);
        self::assertSame('monthly', $vars['vuln.scan_external_cadence']);
        self::assertSame('weekly', $vars['vuln.scan_internal_cadence']);
        self::assertSame('office, hybrid', $vars['working.modes']);
        self::assertSame(50, $vars['cloud.onprem_mix_pct']);
    }

    #[Test]
    public function emptyOptionalInputStillFillsDefaultsWithoutLeftoverNulls(): void
    {
        // No BCM → continuity branch inactive; minimal input → step defaults.
        $run = new WizardRun();
        $run->setTenant($this->makeTenant());
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStandardsAdopted(['iso27001']);

        $result = (new OperationalBaselinesStep())->validate($run, [
            'crypto_allowlist' => ['AES-256'],
            'backup_rpo_hours' => '24',
        ]);
        self::assertSame([], $result['errors']);

        $run->setInputs([
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => $result['normalised_input'],
        ]);
        $vars = $this->makeCollector()->collectFor($run);

        // Step defaults flowed through (patch-SLA BSI defaults + 6 new defaults).
        self::assertSame(4, $vars['patch.sla_critical_hours']);
        self::assertSame(24, $vars['patch.sla_high_hours']);
        self::assertSame(168, $vars['patch.sla_medium_hours']);
        self::assertSame(6, $vars['access.review_cadence_months']);
        self::assertSame('all_users', $vars['mfa.scope']);
        self::assertSame('office, hybrid', $vars['working.modes']);

        // No BCM → continuity var absent (filtered, no leftover {{ }} marker).
        self::assertArrayNotHasKey('continuity.rto_summary', $vars);
    }
}
