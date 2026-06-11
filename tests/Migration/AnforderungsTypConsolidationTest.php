<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WS-1 structural test for Version20260610090000_anforderungstyp_consolidation.
 *
 * Migration behaviour under test:
 *   - Req A: absicherungsStufe=NULL, anforderungsTyp='standard'
 *     → after UP: absicherungsStufe becomes 'standard' (backfill)
 *   - Req B: absicherungsStufe='basis', anforderungsTyp='hoch' (conflict)
 *     → after UP: absicherungsStufe stays 'basis' (canonical wins, conflict NOT overwritten)
 *   - 'erhoeht' maps to 'hoch' during backfill
 *   - Req C: absicherungsStufe='kern' (written by old BsiKompendiumXmlImporter)
 *     → after UP: absicherungsStufe becomes 'hoch' (vocabulary normalization, Step 1)
 *
 * Full DB-roundtrip not required: source-level inspection is the canonical
 * approach for migration tests in this repo (see Vvt5StageLifecycleMigrationTest).
 */
final class AnforderungsTypConsolidationTest extends TestCase
{
    private const string MIGRATION_FILE =
        __DIR__ . '/../../migrations/Version20260610090000_anforderungstyp_consolidation.php';

    private static function readMigrationSource(): string
    {
        $source = file_get_contents(self::MIGRATION_FILE);
        self::assertIsString($source, 'Migration source must be readable: ' . self::MIGRATION_FILE);

        return $source;
    }

    #[Test]
    public function migrationFileExists(): void
    {
        self::assertFileExists(
            self::MIGRATION_FILE,
            'WS-1 consolidation migration must exist at the canonical path.'
        );
    }

    #[Test]
    public function migrationDeclaresNonTransactional(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            'public function isTransactional(): bool',
            $source,
            'CLAUDE.md Pitfall #6: DDL migrations MUST override isTransactional() to false.'
        );
        self::assertStringContainsString(
            'return false;',
            $source,
            'isTransactional() body must return false.'
        );
    }

    #[Test]
    public function migrationBackfillsAbsicherungsStufeWhereNull(): void
    {
        $source = self::readMigrationSource();

        // Must only update rows where absicherungs_stufe is NULL or empty
        // (Req A scenario: null absicherungsStufe gets backfilled from anforderungsTyp)
        self::assertStringContainsString(
            'absicherungs_stufe',
            $source,
            'Migration must reference the absicherungs_stufe column.'
        );
        self::assertStringContainsString(
            'anforderungs_typ',
            $source,
            'Migration must reference the anforderungs_typ column.'
        );

        // Confirm the WHERE clause guards against overwriting existing values (Req B scenario)
        self::assertMatchesRegularExpression(
            '/WHERE\s*\(?\s*absicherungs_stufe\s+IS\s+NULL/i',
            $source,
            'Migration WHERE must check IS NULL so existing absicherungsStufe values are NOT overwritten.'
        );
    }

    #[Test]
    public function migrationMapsErhoehtToHoch(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            "'erhoeht'",
            $source,
            "Migration must normalise legacy 'erhoeht' spelling to 'hoch'."
        );
        self::assertStringContainsString(
            "'hoch'",
            $source,
            "Migration must map to canonical 'hoch' value."
        );
    }

    #[Test]
    public function migrationNormalizesKernToHoch(): void
    {
        $source = self::readMigrationSource();

        // Step 1: migration must UPDATE rows with absicherungs_stufe='kern' to 'hoch'.
        // 'kern' is a BSI Vorgehensweise (level), NOT a tier — the importer bug wrote
        // 'kern' into the tier column for H-type requirements (should be 'hoch').
        self::assertMatchesRegularExpression(
            "/SET\s+absicherungs_stufe\s*=\s*'hoch'\s+WHERE\s+absicherungs_stufe\s*=\s*'kern'/i",
            $source,
            "Migration Step 1 must normalize absicherungs_stufe='kern' → 'hoch' (vocabulary fix)."
        );
    }

    #[Test]
    public function migrationHasDownMethod(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            'public function down(Schema $schema): void',
            $source,
            'Migration must provide a down() path (no-op is acceptable per convention).'
        );
    }

    #[Test]
    public function migrationClassDeclaration(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            'namespace DoctrineMigrations;',
            $source,
            'Migration must live in the DoctrineMigrations namespace.'
        );
        self::assertStringContainsString(
            'Version20260610090000_anforderungstyp_consolidation extends AbstractMigration',
            $source,
            'Migration class must extend AbstractMigration.'
        );
    }
}
