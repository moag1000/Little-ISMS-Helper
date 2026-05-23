<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit Polish (2026-05-22) item 9.5: File-Upload statt Freitext-Pfade.
 *
 *   • Add `training.material_files` (JSON, nullable):
 *     Structured list of uploaded training material file metadata
 *     (filename, originalName, mimeType, size, uploadedAt). Files
 *     live under `public/uploads/training-materials/`. Replaces the
 *     verbose Freitext use of `training.materials` (kept for legacy
 *     read-only display only).
 *
 * `isTransactional() = false` — MySQL implicitly commits ALTER TABLE
 * which invalidates Doctrine's per-migration SAVEPOINT (CLAUDE.md
 * Common-Pitfalls #6).
 */
final class Version20260609100000_training_material_files extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Junior-ISB Polish 9.5: training.material_files JSON column for structured material uploads.';
    }

    public function up(Schema $schema): void
    {
        // Idempotent ADD COLUMN — MariaDB / MySQL >= 8.0.13 supports the
        // `ADD COLUMN IF NOT EXISTS` form natively. Avoids PREPARE/EXECUTE
        // pattern (CLAUDE.md Common-Pitfalls #6).
        $this->addSql(<<<'SQL'
            ALTER TABLE training
                ADD COLUMN IF NOT EXISTS material_files JSON DEFAULT NULL
                    COMMENT '(DC2Type:json)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE training
                DROP COLUMN IF EXISTS material_files
        SQL);
    }
}
