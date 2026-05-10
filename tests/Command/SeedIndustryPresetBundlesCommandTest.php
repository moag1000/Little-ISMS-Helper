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
 * Unit tests for {@see SeedIndustryPresetBundlesCommand} — Phase 4-C /
 * Sprint W4-B. Verifies the seed shape (4 bundles, idempotent) without
 * booting the full kernel.
 */
#[AllowMockObjectsWithoutExpectations]
class SeedIndustryPresetBundlesCommandTest extends TestCase
{
    #[Test]
    public function testFreshSeedCreatesFourBundles(): void
    {
        $repo = $this->createMock(IndustryPresetBundleRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (IndustryPresetBundle $b) use (&$persisted): void {
            $persisted[] = $b;
        });
        $em->expects(self::once())->method('flush');

        $command = new SeedIndustryPresetBundlesCommand($em, $repo);
        $stats = $command->seed();

        // Junior-ISB-friendly defaults pulled in a 5th `custom_general`
        // bundle in May 2026, then Compliance-Manager-Persona feedback
        // (also May 2026) added 3 more presets covering the now-pickable
        // NIS-2 / DORA / BSI-C5 standards. Total = 8. Test name kept
        // for blame-history continuity.
        self::assertSame(8, $stats['created']);
        self::assertSame(0, $stats['updated']);
        self::assertCount(8, $persisted);

        $keys = array_map(static fn (IndustryPresetBundle $b): string => $b->getKey(), $persisted);
        sort($keys);
        self::assertSame(
            [
                'b2c_saas',
                'bafin_dora_marisk_at',
                'custom_general',
                'de_mittelstand_nis2',
                'healthcare',
                'kritis_energie',
                'ot_iec62443',
                'public_sector',
            ],
            $keys,
        );
    }

    #[Test]
    public function testIdempotentReSeedUpdatesExisting(): void
    {
        $existing = new IndustryPresetBundle();
        $existing->setKey(IndustryPresetBundle::KEY_HEALTHCARE);
        $existing->setLabel('Stale label');

        $repo = $this->createMock(IndustryPresetBundleRepository::class);
        $repo->method('findByKey')->willReturnCallback(
            static fn (string $key): ?IndustryPresetBundle => $key === IndustryPresetBundle::KEY_HEALTHCARE
                ? $existing
                : null
        );

        $em = $this->createMock(EntityManagerInterface::class);
        // Existing bundle must NOT be persisted again. With the
        // Compliance-Manager-Persona additions the seeder now creates
        // 7 fresh rows alongside the in-place update of Healthcare.
        $em->expects(self::exactly(7))->method('persist');
        $em->expects(self::once())->method('flush');

        $command = new SeedIndustryPresetBundlesCommand($em, $repo);
        $stats = $command->seed();

        self::assertSame(7, $stats['created']);
        self::assertSame(1, $stats['updated']);
        // Existing entity is updated in place (label rewritten).
        self::assertNotSame('Stale label', $existing->getLabel());
        self::assertSame('Healthcare / Patient Records', $existing->getLabel());
    }

    #[Test]
    public function testHealthcareBundleHasExpectedDefaults(): void
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

        $healthcare = null;
        foreach ($persisted as $b) {
            if ($b->getKey() === IndustryPresetBundle::KEY_HEALTHCARE) {
                $healthcare = $b;
                break;
            }
        }
        self::assertInstanceOf(IndustryPresetBundle::class, $healthcare);
        self::assertSame(['iso27001', 'gdpr'], $healthcare->getPreselectedStandards());
        self::assertSame(1, $healthcare->getDefaultRiskAppetiteTier());
        self::assertSame(4, $healthcare->getDefaultBackupRpoHours());
        self::assertTrue($healthcare->isDpoSectionsAutoEnabled());
        self::assertContains('§ 22 BDSG', $healthcare->getRegulatoryReferences());
        self::assertArrayHasKey('A.5.34', $healthcare->getAnnexAApplicabilityOverrides());
        self::assertSame('applicable', $healthcare->getAnnexAApplicabilityOverrides()['A.5.34']);
    }
}
