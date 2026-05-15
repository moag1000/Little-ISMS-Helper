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

    #[Test]
    public function testHealthcareBundleSetsBdsg22PrivacyOverlay(): void
    {
        // W6-B / spec §7.1 — healthcare bundle wires the § 22 BDSG +
        // § 203 StGB overlay so RoPA / DPIA / §2.16 templates pick up
        // the medical-confidentiality additions downstream.
        $run = new WizardRun();
        $bundle = $this->makeBundle(key: IndustryPresetBundle::KEY_HEALTHCARE);

        $this->applier->applyTo($run, $bundle);

        $bag = $run->getInputs() ?? [];
        self::assertSame(
            [PresetBundleApplier::PRIVACY_OVERLAY_HEALTHCARE_BDSG22],
            $bag['_preset_flags']['privacy_overlays'] ?? null,
        );
    }

    #[Test]
    public function testPublicSectorBundleSetsBbgPrivacyOverlay(): void
    {
        // W6-B / spec §7.6 — public_sector bundle wires the BDSG § 70 ff. /
        // BBG overlay for public-body RoPA / Privacy-Policy variants.
        $run = new WizardRun();
        $bundle = $this->makeBundle(key: IndustryPresetBundle::KEY_PUBLIC_SECTOR);

        $this->applier->applyTo($run, $bundle);

        $bag = $run->getInputs() ?? [];
        self::assertSame(
            [PresetBundleApplier::PRIVACY_OVERLAY_PUBLIC_SECTOR_BBG],
            $bag['_preset_flags']['privacy_overlays'] ?? null,
        );
    }

    #[Test]
    public function testB2cSaasAndOtBundlesAddNoPrivacyOverlay(): void
    {
        // W6-B / spec §7 — B2C-SaaS keeps the default GDPR baseline
        // (no overlay row); OT/IEC 62443 has no privacy concern by
        // definition (operational technology, no personal data flow).
        foreach ([IndustryPresetBundle::KEY_B2C_SAAS, IndustryPresetBundle::KEY_OT_IEC62443] as $key) {
            $run = new WizardRun();
            $bundle = $this->makeBundle(key: $key);

            $this->applier->applyTo($run, $bundle);

            $bag = $run->getInputs() ?? [];
            self::assertArrayNotHasKey(
                'privacy_overlays',
                $bag['_preset_flags'] ?? [],
                sprintf('bundle %s must NOT add a privacy overlay', $key),
            );
        }
    }

    // ── Multi-bundle (applyAll) tests ─────────────────────────────────────

    #[Test]
    public function applyAllWithEmptyListIsNoOp(): void
    {
        $run = new WizardRun();
        $result = $this->applier->applyAll($run, []);

        self::assertSame($run, $result['run']);
        self::assertSame([], $result['conflicts']);
        self::assertNull($run->getInputs());
    }

    #[Test]
    public function applyAllWithSingleBundleMatchesApplyTo(): void
    {
        // Single-bundle applyAll must produce identical results as applyTo.
        $runA = new WizardRun();
        $runB = new WizardRun();
        $bundle = $this->makeBundle();

        $this->applier->applyTo($runA, $bundle);
        $this->applier->applyAll($runB, [$bundle]);

        $bagA = $runA->getInputs() ?? [];
        $bagB = $runB->getInputs() ?? [];

        self::assertSame(
            $bagA[WizardStepKeys::STEP_WELCOME]['standards'] ?? null,
            $bagB[WizardStepKeys::STEP_WELCOME]['standards'] ?? null,
        );
        self::assertSame(
            $bagA[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier'] ?? null,
            $bagB[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier'] ?? null,
        );
    }

    #[Test]
    public function applyAllMergesBundlesInOrderLaterWins(): void
    {
        // Healthcare: RPO=4 h, risk_tier=1
        // B2C-SaaS: RPO=24 h, risk_tier=2 (via makeBundle which sets tier=1;
        //   we need distinct values so create a custom second bundle).
        $bundleA = $this->makeBundle(key: 'healthcare');
        // bundleA: tier=1, RPO=4

        $bundleB = new IndustryPresetBundle();
        $bundleB
            ->setKey(IndustryPresetBundle::KEY_B2C_SAAS)
            ->setLabel('B2C-SaaS')
            ->setStandard(IndustryPresetBundle::STANDARD_ISO_GDPR)
            ->setPreselectedStandards(['iso27001', 'gdpr', 'nis2'])
            ->setDefaultRiskAppetiteTier(3)           // different from bundleA's 1
            ->setDefaultDataClassificationLevels(3)
            ->setDefaultBackupRpoHours(24)            // different from bundleA's 4
            ->setDefaultPatchSlaCriticalHours(72)
            ->setAnnexAApplicabilityOverrides([])
            ->setDpoSectionsAutoEnabled(true)
            ->setRegulatoryReferences([])
            ->setIsActive(true);

        $run = new WizardRun();
        $result = $this->applier->applyAll($run, [$bundleA, $bundleB]);

        $bag = $run->getInputs() ?? [];

        // Later-wins: bundleB's values overwrite bundleA's for scalars.
        self::assertSame(
            3,
            $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier'],
            'risk_appetite_tier: later bundle (B2C-SaaS, tier=3) must win over earlier (healthcare, tier=1)',
        );
        self::assertSame(
            24,
            $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['backup_rpo_hours'],
            'backup_rpo_hours: later bundle (B2C-SaaS, 24 h) must win over earlier (healthcare, 4 h)',
        );

        // UNION: standards from both bundles merged.
        $standards = $bag[WizardStepKeys::STEP_WELCOME]['standards'] ?? [];
        self::assertContains('iso27001', $standards);
        self::assertContains('gdpr', $standards);
        self::assertContains('nis2', $standards);

        // Multi-key tracker populated with both bundle keys.
        $keys = $bag[WizardStepKeys::STEP_WELCOME]['industry_preset_bundle_keys'] ?? [];
        self::assertContains('healthcare', $keys);
        self::assertContains('b2c_saas', $keys);

        // Conflicts detected for the differing scalar fields.
        self::assertArrayHasKey('risk_appetite_tier', $result['conflicts']);
        self::assertArrayHasKey('backup_rpo_hours', $result['conflicts']);
        self::assertSame(['healthcare' => 1, 'b2c_saas' => 3], $result['conflicts']['risk_appetite_tier']);
    }

    #[Test]
    public function applyAllUserInputWinsOverAllBundles(): void
    {
        // User has pre-set risk_appetite_tier=5 and backup_rpo_hours=48.
        $run = new WizardRun();
        $run->setInputs([
            WizardStepKeys::STEP_RISK_CLASSIFICATION => [
                'risk_appetite_tier' => 5,
                'data_classification_levels' => 3,
            ],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'backup_rpo_hours' => 48,
                'patch_sla_hours' => ['critical' => 12],
            ],
        ]);
        $run->setStandardsAdopted(['iso27001', 'dora']);

        $bundleA = $this->makeBundle(key: 'healthcare'); // tier=1, RPO=4
        $bundleB = $this->makeBundle(key: 'b2c_saas');   // tier=1, RPO=4 (same factory)

        $this->applier->applyAll($run, [$bundleA, $bundleB]);

        $bag = $run->getInputs() ?? [];
        // User values survive — bundles must not overwrite explicit user input.
        self::assertSame(5, $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier']);
        self::assertSame(48, $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['backup_rpo_hours']);
        self::assertSame(12, $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['patch_sla_hours']['critical']);

        // User standards are unioned with bundle standards.
        $standards = $run->getStandardsAdopted();
        self::assertContains('iso27001', $standards ?? []);
        self::assertContains('dora', $standards ?? []);
        self::assertContains('gdpr', $standards ?? []); // from bundle
    }

    #[Test]
    public function applyAllUnionsStandardsFromAllBundles(): void
    {
        $bundleA = new IndustryPresetBundle();
        $bundleA->setKey('de_mittelstand_nis2')
            ->setLabel('DE-Mittelstand NIS-2')
            ->setStandard(IndustryPresetBundle::STANDARD_ISO27001)
            ->setPreselectedStandards(['iso27001', 'nis2'])
            ->setDefaultRiskAppetiteTier(2)
            ->setDefaultDataClassificationLevels(3)
            ->setDefaultBackupRpoHours(12)
            ->setDefaultPatchSlaCriticalHours(48)
            ->setAnnexAApplicabilityOverrides([])
            ->setDpoSectionsAutoEnabled(false)
            ->setRegulatoryReferences([])
            ->setIsActive(true);

        $bundleB = new IndustryPresetBundle();
        $bundleB->setKey('bafin_dora_marisk_at')
            ->setLabel('BaFin DORA')
            ->setStandard(IndustryPresetBundle::STANDARD_ISO_DORA)
            ->setPreselectedStandards(['iso27001', 'dora', 'gdpr', 'bcm'])
            ->setDefaultRiskAppetiteTier(1)
            ->setDefaultDataClassificationLevels(4)
            ->setDefaultBackupRpoHours(4)
            ->setDefaultPatchSlaCriticalHours(24)
            ->setAnnexAApplicabilityOverrides([])
            ->setDpoSectionsAutoEnabled(true)
            ->setRegulatoryReferences([])
            ->setIsActive(true);

        $run = new WizardRun();
        $result = $this->applier->applyAll($run, [$bundleA, $bundleB]);

        $bag = $run->getInputs() ?? [];
        $standards = $bag[WizardStepKeys::STEP_WELCOME]['standards'] ?? [];

        self::assertContains('iso27001', $standards);
        self::assertContains('nis2', $standards);
        self::assertContains('dora', $standards);
        self::assertContains('gdpr', $standards);
        self::assertContains('bcm', $standards);

        // No duplicates.
        self::assertSame(count($standards), count(array_unique($standards)));

        // Conflicts for all differing scalar fields.
        self::assertArrayHasKey('risk_appetite_tier', $result['conflicts']);
        self::assertArrayHasKey('data_classification_levels', $result['conflicts']);
        self::assertArrayHasKey('backup_rpo_hours', $result['conflicts']);

        // Later-wins: bafin_dora values win.
        self::assertSame(1, $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier']);
        self::assertSame(4, $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['backup_rpo_hours']);
    }

    #[Test]
    public function applyAllNonConflictingBundlesProduceNoConflictMap(): void
    {
        // Two bundles that agree on all scalar fields produce no conflicts.
        $bundleA = $this->makeBundle(key: 'healthcare');  // tier=1, RPO=4
        $bundleB = $this->makeBundle(key: 'public_sector'); // same factory → tier=1, RPO=4

        $run = new WizardRun();
        $result = $this->applier->applyAll($run, [$bundleA, $bundleB]);

        self::assertSame([], $result['conflicts']);
    }
}
