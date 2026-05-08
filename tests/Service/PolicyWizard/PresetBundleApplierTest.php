<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\IndustryPresetBundle;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\PresetBundleApplier;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PresetBundleApplier} — Phase 4-C / Sprint W4-B.
 *
 * The applier merges an IndustryPresetBundle's defaults into a
 * WizardRun's `inputs` snapshot. Existing user input is honoured;
 * inactive bundles are rejected.
 */
class PresetBundleApplierTest extends TestCase
{
    private PresetBundleApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new PresetBundleApplier();
    }

    private function makeBundle(string $key = 'healthcare', bool $active = true): IndustryPresetBundle
    {
        $bundle = new IndustryPresetBundle();
        $bundle
            ->setKey($key)
            ->setLabel(ucfirst($key))
            ->setStandard(IndustryPresetBundle::STANDARD_ISO_GDPR)
            ->setPreselectedStandards(['iso27001', 'gdpr'])
            ->setDefaultRiskAppetiteTier(1)
            ->setDefaultDataClassificationLevels(4)
            ->setDefaultBackupRpoHours(4)
            ->setDefaultPatchSlaCriticalHours(24)
            ->setAnnexAApplicabilityOverrides(['A.5.34' => 'applicable'])
            ->setDpoSectionsAutoEnabled(true)
            ->setRegulatoryReferences(['§ 22 BDSG'])
            ->setIsActive($active);
        return $bundle;
    }

    #[Test]
    public function testAppliesPreselectedStandards(): void
    {
        $run = new WizardRun();
        $bundle = $this->makeBundle();

        $this->applier->applyTo($run, $bundle);

        $bag = $run->getInputs() ?? [];
        $welcome = $bag[WizardStepKeys::STEP_WELCOME] ?? [];
        self::assertSame(['iso27001', 'gdpr'], $welcome['standards'] ?? null);
        self::assertSame('healthcare', $welcome['industry_preset_bundle_key'] ?? null);
        // First-class column synchronised so downstream steps see it.
        self::assertSame(['iso27001', 'gdpr'], $run->getStandardsAdopted());
    }

    #[Test]
    public function testAppliesRiskAppetiteTier(): void
    {
        $run = new WizardRun();
        $bundle = $this->makeBundle();

        $this->applier->applyTo($run, $bundle);

        $bag = $run->getInputs() ?? [];
        $risk = $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? [];
        self::assertSame(1, $risk['risk_appetite_tier'] ?? null);
        self::assertSame(4, $risk['data_classification_levels'] ?? null);
        self::assertSame(['A.5.34' => 'applicable'], $risk['annex_a_applicability'] ?? null);
    }

    #[Test]
    public function testAppliesBackupRpo(): void
    {
        $run = new WizardRun();
        $bundle = $this->makeBundle();

        $this->applier->applyTo($run, $bundle);

        $bag = $run->getInputs() ?? [];
        $op = $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? [];
        self::assertSame(4, $op['backup_rpo_hours'] ?? null);
        self::assertSame(24, $op['patch_sla_hours']['critical'] ?? null);

        // DPO auto-flag carried over.
        self::assertTrue($bag['_preset_flags']['dpo_sections_auto_enabled'] ?? false);
    }

    #[Test]
    public function testRespectsExistingUserInput(): void
    {
        $run = new WizardRun();
        $run->setInputs([
            WizardStepKeys::STEP_WELCOME => [
                'standards' => ['iso27001', 'dora'],
            ],
            WizardStepKeys::STEP_RISK_CLASSIFICATION => [
                'risk_appetite_tier' => 5,
                'data_classification_levels' => 3,
                'annex_a_applicability' => ['A.5.34' => 'not_applicable'],
            ],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'backup_rpo_hours' => 72,
                'patch_sla_hours' => ['critical' => 12],
            ],
        ]);
        $run->setStandardsAdopted(['iso27001', 'dora']);

        $bundle = $this->makeBundle();
        $this->applier->applyTo($run, $bundle);

        $bag = $run->getInputs() ?? [];
        // User input wins everywhere.
        self::assertSame(['iso27001', 'dora'], $bag[WizardStepKeys::STEP_WELCOME]['standards']);
        self::assertSame(5, $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier']);
        self::assertSame(3, $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['data_classification_levels']);
        self::assertSame(
            ['A.5.34' => 'not_applicable'],
            $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['annex_a_applicability'],
        );
        self::assertSame(72, $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['backup_rpo_hours']);
        self::assertSame(12, $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['patch_sla_hours']['critical']);

        // First-class column NOT overwritten when caller already set it.
        self::assertSame(['iso27001', 'dora'], $run->getStandardsAdopted());

        // The bundle key is still recorded on the welcome slot for audit.
        self::assertSame('healthcare', $bag[WizardStepKeys::STEP_WELCOME]['industry_preset_bundle_key']);
    }

    #[Test]
    public function testInactiveBundleRejected(): void
    {
        $run = new WizardRun();
        $bundle = $this->makeBundle(active: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->applier->applyTo($run, $bundle);
    }
}
