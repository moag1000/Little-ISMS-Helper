<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\IndustryPresetBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see IndustryPresetBundle} — Phase 4-C / Sprint W4-B.
 */
class IndustryPresetBundleTest extends TestCase
{
    #[Test]
    public function testRoundTripsAllFields(): void
    {
        $bundle = new IndustryPresetBundle();
        $bundle
            ->setKey(IndustryPresetBundle::KEY_HEALTHCARE)
            ->setLabel('Healthcare')
            ->setDescription('Hospitals + practices')
            ->setStandard(IndustryPresetBundle::STANDARD_ISO_GDPR)
            ->setPreselectedStandards(['iso27001', 'gdpr'])
            ->setDefaultRiskAppetiteTier(1)
            ->setDefaultDataClassificationLevels(4)
            ->setDefaultBackupRpoHours(4)
            ->setDefaultPatchSlaCriticalHours(24)
            ->setAnnexAApplicabilityOverrides(['A.5.34' => 'applicable'])
            ->setTopicAudienceOverrides(['privacy' => ['ROLE_DPO']])
            ->setDpoSectionsAutoEnabled(true)
            ->setRegulatoryReferences(['§ 22 BDSG'])
            ->setIsActive(true)
            ->setVersion(1);

        $this->assertNull($bundle->getId());
        $this->assertSame('healthcare', $bundle->getKey());
        $this->assertSame('Healthcare', $bundle->getLabel());
        $this->assertSame('Hospitals + practices', $bundle->getDescription());
        $this->assertSame(IndustryPresetBundle::STANDARD_ISO_GDPR, $bundle->getStandard());
        $this->assertSame(['iso27001', 'gdpr'], $bundle->getPreselectedStandards());
        $this->assertSame(1, $bundle->getDefaultRiskAppetiteTier());
        $this->assertSame(4, $bundle->getDefaultDataClassificationLevels());
        $this->assertSame(4, $bundle->getDefaultBackupRpoHours());
        $this->assertSame(24, $bundle->getDefaultPatchSlaCriticalHours());
        $this->assertSame(['A.5.34' => 'applicable'], $bundle->getAnnexAApplicabilityOverrides());
        $this->assertSame(['privacy' => ['ROLE_DPO']], $bundle->getTopicAudienceOverrides());
        $this->assertTrue($bundle->isDpoSectionsAutoEnabled());
        $this->assertSame(['§ 22 BDSG'], $bundle->getRegulatoryReferences());
        $this->assertTrue($bundle->isActive());
        $this->assertSame(1, $bundle->getVersion());
    }

    #[Test]
    public function testDefaultsAreBalanced(): void
    {
        $bundle = new IndustryPresetBundle();

        // Constructor-side defaults — verifies the entity is usable
        // even before the seed command writes anything.
        $this->assertSame(IndustryPresetBundle::STANDARD_ISO27001, $bundle->getStandard());
        $this->assertSame(3, $bundle->getDefaultRiskAppetiteTier());
        $this->assertSame(3, $bundle->getDefaultDataClassificationLevels());
        $this->assertSame(24, $bundle->getDefaultBackupRpoHours());
        $this->assertSame(72, $bundle->getDefaultPatchSlaCriticalHours());
        $this->assertSame([], $bundle->getPreselectedStandards());
        $this->assertSame([], $bundle->getAnnexAApplicabilityOverrides());
        $this->assertSame([], $bundle->getTopicAudienceOverrides());
        $this->assertSame([], $bundle->getRegulatoryReferences());
        $this->assertFalse($bundle->isDpoSectionsAutoEnabled());
        $this->assertTrue($bundle->isActive());
        $this->assertSame(1, $bundle->getVersion());
    }

    #[Test]
    public function testKeyConstantsCoverAllV1Bundles(): void
    {
        $this->assertContains(IndustryPresetBundle::KEY_HEALTHCARE, IndustryPresetBundle::ALLOWED_KEYS);
        $this->assertContains(IndustryPresetBundle::KEY_PUBLIC_SECTOR, IndustryPresetBundle::ALLOWED_KEYS);
        $this->assertContains(IndustryPresetBundle::KEY_B2C_SAAS, IndustryPresetBundle::ALLOWED_KEYS);
        $this->assertContains(IndustryPresetBundle::KEY_OT_IEC62443, IndustryPresetBundle::ALLOWED_KEYS);
        $this->assertCount(4, IndustryPresetBundle::ALLOWED_KEYS);

        $this->assertContains(IndustryPresetBundle::STANDARD_ISO27001, IndustryPresetBundle::ALLOWED_STANDARDS);
        $this->assertContains(IndustryPresetBundle::STANDARD_ISO_GDPR, IndustryPresetBundle::ALLOWED_STANDARDS);
        $this->assertContains(IndustryPresetBundle::STANDARD_ISO_BSI, IndustryPresetBundle::ALLOWED_STANDARDS);
    }
}
