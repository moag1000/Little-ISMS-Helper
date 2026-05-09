<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\Step\OperationalBaselinesStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Junior-Implementer-Persona feedback (May 2026) — Wish #1.
 *
 * Verifies that {@see OperationalBaselinesStep::defaults()} pre-fills
 * the `crypto_allowlist` slot with a BSI-TR-02102:2024-1 conformant
 * algorithm set when the user lands on Step 5 with an empty slot, and
 * that explicit user input is preserved on subsequent renders.
 */
final class OperationalBaselinesStepBsiTr02102DefaultsTest extends TestCase
{
    private function makeRun(array $standards = ['iso27001'], array $inputs = []): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted($standards);
        $run->setStep(WizardStepKeys::STEP_OPERATIONAL_BASELINES);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs($inputs);
        return $run;
    }

    #[Test]
    public function testBsiCryptoAllowlistPrefilledWhenEmpty(): void
    {
        $step = new OperationalBaselinesStep();
        // BSI in scope and slot empty → BSI default set populated.
        $run = $this->makeRun(['iso27001', 'bsi']);

        $defaults = $step->defaults($run);

        // Spot-check: the BSI default set is non-empty and includes
        // the AES-256-GCM + EdDSA suites required by TR-02102.
        self::assertIsArray($defaults['crypto_allowlist']);
        self::assertNotEmpty($defaults['crypto_allowlist']);
        self::assertContains('AES-256-GCM', $defaults['crypto_allowlist']);
        self::assertContains('AES-128-GCM', $defaults['crypto_allowlist']);
        self::assertContains('CHACHA20-POLY1305', $defaults['crypto_allowlist']);
        self::assertContains('SHA-256', $defaults['crypto_allowlist']);
        self::assertContains('ED25519', $defaults['crypto_allowlist']);
        self::assertContains('RSA-3072', $defaults['crypto_allowlist']);
        // Legacy / weak algorithms must NOT be in the default set.
        self::assertNotContains('3DES', $defaults['crypto_allowlist']);
        self::assertNotContains('MD5', $defaults['crypto_allowlist']);
        self::assertNotContains('SHA-1', $defaults['crypto_allowlist']);
        self::assertNotContains('RSA-2048', $defaults['crypto_allowlist']);
        // Constant exposure for downstream services / reporting.
        self::assertSame(
            OperationalBaselinesStep::BSI_TR02102_DEFAULT_ALGOS,
            $defaults['crypto_allowlist'],
        );
    }

    #[Test]
    public function testUserOverridePersists(): void
    {
        $step = new OperationalBaselinesStep();
        $userPick = ['AES-256-GCM', 'ED25519'];
        $run = $this->makeRun(['iso27001', 'bsi'], [
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'crypto_allowlist' => $userPick,
            ],
        ]);

        $defaults = $step->defaults($run);

        // User pick wins — defaults() must NOT overwrite it with the
        // BSI default-set on a subsequent render.
        self::assertSame($userPick, $defaults['crypto_allowlist']);
    }

    #[Test]
    public function testNonBsiTenantStillGetsModernDefaults(): void
    {
        $step = new OperationalBaselinesStep();
        // No BSI in scope — the same modern + safe set is applied; the
        // template differentiates only the tooltip wording.
        $run = $this->makeRun(['iso27001', 'gdpr']);

        $defaults = $step->defaults($run);

        self::assertIsArray($defaults['crypto_allowlist']);
        self::assertNotEmpty($defaults['crypto_allowlist']);
        self::assertContains('AES-256-GCM', $defaults['crypto_allowlist']);
        self::assertContains('CHACHA20-POLY1305', $defaults['crypto_allowlist']);
        // Same set is delivered regardless of BSI scope — tooltip in
        // template carries the contextual messaging.
        self::assertSame(
            OperationalBaselinesStep::BSI_TR02102_DEFAULT_ALGOS,
            $defaults['crypto_allowlist'],
        );
    }
}
