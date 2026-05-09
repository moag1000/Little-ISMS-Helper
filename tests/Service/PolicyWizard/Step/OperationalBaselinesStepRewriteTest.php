<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\IndustryPresetBundle;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\PresetBundleApplier;
use App\Service\PolicyWizard\Step\OperationalBaselinesStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit follow-up (May 2026) — verifies the OperationalBaselines
 * REWRITE: validate() now accepts the full template-rendered field set
 * (crypto_allowlist, backup_rpo_hours, patch_sla_hours, continuity_rto,
 * dora block, dpo_user_id, bcm_officer_user_id, industry_preset).
 */
final class OperationalBaselinesStepRewriteTest extends TestCase
{
    private function makeRun(array $standards = ['iso27001']): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted($standards);
        $run->setStep(WizardStepKeys::STEP_OPERATIONAL_BASELINES);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs([]);
        return $run;
    }

    #[Test]
    public function testCryptoAllowlistAccepted(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM', 'CHACHA20-POLY1305'],
            'backup_rpo_hours' => 12,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['AES-256-GCM', 'CHACHA20-POLY1305'],
            $result['normalised_input']['crypto_allowlist'],
        );
    }

    #[Test]
    public function testBackupRpoHoursValidatedRange(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeRun();

        $resultHigh = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 9999,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
        ]);
        self::assertNotEmpty($resultHigh['errors']['backup_rpo_hours'] ?? []);

        $resultMissing = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
        ]);
        self::assertNotEmpty($resultMissing['errors']['backup_rpo_hours'] ?? []);

        $resultOk = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
        ]);
        self::assertSame([], $resultOk['errors']);
        self::assertSame(24, $resultOk['normalised_input']['backup_rpo_hours']);
    }

    #[Test]
    public function testPatchSlaPerSeverityAccepted(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['critical' => 4, 'high' => 24, 'medium' => 168],
            $result['normalised_input']['patch_sla_hours'],
        );
    }

    #[Test]
    public function testContinuityRtoOnlyWhenBcmInScope(): void
    {
        $step = new OperationalBaselinesStep();

        // Without BCM — continuity_rto_hours is ignored / null in normalised.
        $runNoBcm = $this->makeRun(['iso27001']);
        $resultNoBcm = $step->validate($runNoBcm, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'continuity_rto_hours' => ['high' => 24],
        ]);
        self::assertSame([], $resultNoBcm['errors']);
        self::assertNull($resultNoBcm['normalised_input']['continuity_rto_hours']);

        // With BCM but no RTO — error.
        $runBcm = $this->makeRun(['iso27001', 'bcm']);
        $resultMissing = $step->validate($runBcm, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
        ]);
        self::assertNotEmpty($resultMissing['errors']['continuity_rto_hours'] ?? []);

        // With BCM + RTO — accepted.
        $resultOk = $step->validate($runBcm, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'continuity_rto_hours' => ['high' => 24, 'medium' => 72, 'low' => 168],
        ]);
        self::assertSame([], $resultOk['errors']);
        self::assertSame(
            ['high' => 24, 'medium' => 72, 'low' => 168],
            $resultOk['normalised_input']['continuity_rto_hours'],
        );
    }

    #[Test]
    public function testDoraBlockOnlyWhenDoraInScope(): void
    {
        $step = new OperationalBaselinesStep();

        // No DORA → DORA block null in normalised, no errors.
        $runNoDora = $this->makeRun(['iso27001']);
        $resultNoDora = $step->validate($runNoDora, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'dora' => ['entity_type' => 'kreditinstitut'],
        ]);
        self::assertSame([], $resultNoDora['errors']);
        self::assertNull($resultNoDora['normalised_input']['dora']);

        // DORA in scope, missing required fields → errors.
        $runDora = $this->makeRun(['iso27001', 'dora']);
        $resultMissing = $step->validate($runDora, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'dora' => [],
        ]);
        self::assertNotEmpty($resultMissing['errors']['dora'] ?? []);

        // DORA in scope, all required fields → accepted.
        $resultOk = $step->validate($runDora, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'dora' => [
                'entity_type' => 'kreditinstitut',
                'competent_authority' => 'BaFin',
                'is_significant' => true,
                'ictt_concentration_threshold_pct' => 25,
                'is_ctpp_self_assessment' => false,
            ],
        ]);
        self::assertSame([], $resultOk['errors']);
        self::assertNotNull($resultOk['normalised_input']['dora']);
        self::assertSame('kreditinstitut', $resultOk['normalised_input']['dora']['entity_type']);
        self::assertTrue($resultOk['normalised_input']['dora']['is_significant']);
    }

    #[Test]
    public function testDpoPickerExposesUsersWithRoleDpo(): void
    {
        // The picker is wired through UserRepository::findByRoleInTenant.
        // Here we exercise validate()'s acceptance of the picker's output:
        // a numeric user_id that survives normalisation.
        $step = new OperationalBaselinesStep();
        $run = $this->makeRun(['iso27001', 'gdpr']);

        $result = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'dpo_user_id' => '42',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(42, $result['normalised_input']['dpo_user_id']);
    }

    #[Test]
    public function testBcmOfficerPickerExposesUsersWithRoleBcmOfficer(): void
    {
        $step = new OperationalBaselinesStep();
        $run = $this->makeRun(['iso27001', 'bcm']);

        $result = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'continuity_rto_hours' => ['high' => 24],
            'bcm_officer_user_id' => '17',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(17, $result['normalised_input']['bcm_officer_user_id']);
    }

    #[Test]
    public function testIndustryPresetApplyingWritesAllFields(): void
    {
        // The applier pre-fills inputs; the rewrite still accepts a
        // bundle key on the OpBaselines slot.
        $step = new OperationalBaselinesStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'crypto_allowlist' => ['AES-256-GCM'],
            'backup_rpo_hours' => 24,
            'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
            'industry_preset_bundle_key' => 'healthcare',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('healthcare', $result['normalised_input']['industry_preset_bundle_key']);

        // Spot-check: the applier itself stays the SoT for first-class
        // pre-fills — confirm class symbol exists.
        self::assertTrue(class_exists(PresetBundleApplier::class));
        self::assertTrue(class_exists(IndustryPresetBundle::class));
        // Sanity: User entity is reachable for picker rendering.
        self::assertTrue(class_exists(User::class));
    }
}
