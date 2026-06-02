<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Command\SeedIndustryPresetBundlesCommand;
use App\Entity\IndustryPresetBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the seed-migration ↔ seed-command parity for IndustryPresetBundles.
 *
 * Version20260630100000 hard-codes the 7 sector bundles as plain SQL so a
 * fresh `doctrine:migrations:migrate` populates the Wizard Step-1 dropdown
 * without the manual `app:policy-wizard:seed-bundles` command. The command
 * remains the authoritative source. If someone edits a bundle in the command
 * but forgets the migration (or vice-versa), a fresh deploy and a seeded
 * deploy would disagree — this test fails loudly on that drift.
 */
final class SectorPresetBundleMigrationParityTest extends TestCase
{
    private const MIGRATION = 'DoctrineMigrations\\Version20260630100000_policy_wizard_sector_preset_bundles';

    /** @return array<string, array<string, mixed>> keyed by bundle key */
    private function migrationBundles(): array
    {
        require_once \dirname(__DIR__, 2)
            . '/migrations/Version20260630100000_policy_wizard_sector_preset_bundles.php';
        $rc = new ReflectionClass(self::MIGRATION);
        $obj = $rc->newInstanceWithoutConstructor();
        $method = $rc->getMethod('bundles');
        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($obj);

        return $this->keyBy($rows);
    }

    /** @return array<string, array<string, mixed>> keyed by bundle key, custom_general dropped */
    private function commandDefinitions(): array
    {
        $rc = new ReflectionClass(SeedIndustryPresetBundlesCommand::class);
        $obj = $rc->newInstanceWithoutConstructor();
        $method = $rc->getMethod('definitions');
        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($obj);

        $byKey = $this->keyBy($rows);
        unset($byKey[IndustryPresetBundle::KEY_CUSTOM_GENERAL]);

        return $byKey;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyBy(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = $row;
        }

        return $out;
    }

    #[Test]
    public function migrationSeedsExactlyTheSevenSectorBundles(): void
    {
        $migration = $this->migrationBundles();

        self::assertCount(7, $migration);
        self::assertSame(
            [
                'healthcare',
                'public_sector',
                'b2c_saas',
                'ot_iec62443',
                'de_mittelstand_nis2',
                'bafin_dora_marisk_at',
                'kritis_energie',
            ],
            array_keys($migration),
        );
        // custom_general is seeded by Version20260509020000, NOT here.
        self::assertArrayNotHasKey(IndustryPresetBundle::KEY_CUSTOM_GENERAL, $migration);
    }

    #[Test]
    public function migrationDataMatchesSeedCommandVerbatim(): void
    {
        $command = $this->commandDefinitions();
        $migration = $this->migrationBundles();

        self::assertSame(
            array_keys($command),
            array_keys($migration),
            'migration must cover exactly the command sector bundles (order-independent set)',
        );

        foreach ($command as $key => $def) {
            self::assertEquals(
                $def,
                $migration[$key],
                sprintf('bundle "%s" drifted between command and migration', $key),
            );
        }
    }
}
