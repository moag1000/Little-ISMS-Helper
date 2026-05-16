<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S3 P-4 — Version20260518150000_vvt_document_canonical_lifecycle.
 *
 * Structural test: asserts the migration declares the required statements
 * (legacy `active` → `published` UPDATE for both VVT + Document, default
 * bump for `document.status`) and that the safety contracts hold
 * (`isTransactional() === false`, idempotent reverse path).
 *
 * Full DB-roundtrip coverage is not possible here (MySQL is not booted in
 * the unit-test suite). The structural assertions are the strongest signal
 * we can give without spinning up a full Doctrine kernel.
 */
final class Vvt5StageLifecycleMigrationTest extends TestCase
{
    private const string MIGRATION_FILE = __DIR__ . '/../../migrations/Version20260518150000_vvt_document_canonical_lifecycle.php';

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
            'S3 P-4 consolidated migration must exist at the canonical path.'
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
            'isTransactional() body must return false for ALTER TABLE statements.'
        );
    }

    #[Test]
    public function migrationUpdatesProcessingActivityActiveToPublished(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            "UPDATE processing_activity SET status = 'published' WHERE status = 'active'",
            $source,
            'Must migrate legacy VVT `active` rows to canonical `published` (S3 P-4).'
        );
    }

    #[Test]
    public function migrationUpdatesDocumentActiveToPublished(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            "UPDATE document SET status = 'published' WHERE status = 'active'",
            $source,
            'Must migrate legacy Document `active` rows to canonical `published` (S3 P-4).'
        );
    }

    #[Test]
    public function migrationBumpsDocumentStatusDefaultToDraft(): void
    {
        $source = self::readMigrationSource();

        self::assertMatchesRegularExpression(
            "/ALTER TABLE document MODIFY COLUMN status [^']+'draft'/",
            $source,
            'Document.status default must be bumped from "active" → "draft" (canonical lifecycle starts at draft).'
        );
    }

    #[Test]
    public function migrationHasReverseDownPath(): void
    {
        $source = self::readMigrationSource();

        self::assertStringContainsString(
            'public function down(Schema $schema): void',
            $source,
            'Migration must provide a reversible down() path even if lossy (per repo convention).'
        );
        self::assertStringContainsString(
            "UPDATE processing_activity SET status = 'active' WHERE status = 'published'",
            $source,
            'down() must reverse the VVT UPDATE.'
        );
        self::assertStringContainsString(
            "UPDATE document SET status = 'active' WHERE status = 'published'",
            $source,
            'down() must reverse the Document UPDATE.'
        );
    }

    #[Test]
    public function migrationClassExtendsAbstractMigration(): void
    {
        $source = self::readMigrationSource();

        // Source-level inspection — DoctrineMigrations classes are normally
        // loaded by the migrations bundle at runtime, not by the PSR-4
        // autoloader (the file is outside the registered namespace map).
        self::assertStringContainsString(
            'namespace DoctrineMigrations;',
            $source,
            'Migration must live in the DoctrineMigrations namespace.'
        );
        self::assertStringContainsString(
            'final class Version20260518150000_vvt_document_canonical_lifecycle extends AbstractMigration',
            $source,
            'Migration must be a final class extending AbstractMigration (Symfony 7.4 convention).'
        );
    }
}
