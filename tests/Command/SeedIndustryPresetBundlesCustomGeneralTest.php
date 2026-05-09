<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedIndustryPresetBundlesCommand;
use App\Entity\IndustryPresetBundle;
use App\Repository\IndustryPresetBundleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Junior-Implementer-Persona feedback (May 2026) — Wish #5.
 *
 * Verifies the new `custom_general` IndustryPresetBundle is seeded
 * with neutral defaults (ISO 27001 only, balanced risk appetite, no
 * sector-specific Annex-A overrides) so Junior-ISBs without a clear
 * industry match still get a safe Step-1 dropdown entry.
 */
#[AllowMockObjectsWithoutExpectations]
class SeedIndustryPresetBundlesCustomGeneralTest extends TestCase
{
    #[Test]
    public function testCustomGeneralBundleSeeded(): void
    {
        $repo = $this->createMock(IndustryPresetBundleRepository::class);
        $repo->method('findByKey')->willReturn(null);

        /** @var list<IndustryPresetBundle> $persisted */
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (IndustryPresetBundle $b) use (&$persisted): void {
            $persisted[] = $b;
        });

        $command = new SeedIndustryPresetBundlesCommand($em, $repo);
        $command->seed();

        $custom = null;
        foreach ($persisted as $b) {
            if ($b->getKey() === IndustryPresetBundle::KEY_CUSTOM_GENERAL) {
                $custom = $b;
                break;
            }
        }
        self::assertInstanceOf(IndustryPresetBundle::class, $custom);
        self::assertSame('custom_general', $custom->getKey());
        self::assertTrue($custom->isActive());
    }

    #[Test]
    public function testCustomGeneralBundleHasNeutralDefaults(): void
    {
        $repo = $this->createMock(IndustryPresetBundleRepository::class);
        $repo->method('findByKey')->willReturn(null);

        /** @var list<IndustryPresetBundle> $persisted */
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (IndustryPresetBundle $b) use (&$persisted): void {
            $persisted[] = $b;
        });

        $command = new SeedIndustryPresetBundlesCommand($em, $repo);
        $command->seed();

        $custom = null;
        foreach ($persisted as $b) {
            if ($b->getKey() === IndustryPresetBundle::KEY_CUSTOM_GENERAL) {
                $custom = $b;
                break;
            }
        }
        self::assertInstanceOf(IndustryPresetBundle::class, $custom);
        // Only mandatory ISO 27001 baseline pre-selected.
        self::assertSame(['iso27001'], $custom->getPreselectedStandards());
        self::assertSame(IndustryPresetBundle::STANDARD_ISO27001, $custom->getStandard());
        // Balanced risk appetite (tier 3).
        self::assertSame(3, $custom->getDefaultRiskAppetiteTier());
        // 3 classification levels (BSI minimum).
        self::assertSame(3, $custom->getDefaultDataClassificationLevels());
        // 24h RPO + 72h critical patch SLA = neutral mid-market values.
        self::assertSame(24, $custom->getDefaultBackupRpoHours());
        self::assertSame(72, $custom->getDefaultPatchSlaCriticalHours());
        // No sector-specific Annex-A overrides — user fills SoA manually.
        self::assertSame([], $custom->getAnnexAApplicabilityOverrides());
        self::assertSame([], $custom->getTopicAudienceOverrides());
        // No DPO auto-enable — user opts in via Step 1 GDPR/27701 picker.
        self::assertFalse($custom->isDpoSectionsAutoEnabled());
        self::assertSame([], $custom->getRegulatoryReferences());
    }
}
