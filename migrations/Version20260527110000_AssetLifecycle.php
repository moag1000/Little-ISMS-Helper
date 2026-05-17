<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle — Asset custom physical lifecycle state-machine.
 *
 * Changes:
 *  1. Adds `lock_version INT NOT NULL DEFAULT 0` for optimistic locking
 *     (required by LifecycleService to detect concurrent transition conflicts).
 *  2. Backfills any NULL/empty status rows to 'active' (existing operational rows).
 *
 * Note: 'draft' is added as a valid status via Assert\Choice in Asset.php but
 * no existing rows are expected to have it — only newly created assets will
 * start at 'draft'. Existing assets keep their current status values unchanged.
 *
 * CLAUDE.md pitfall #6: DDL migration — isTransactional() = false.
 */
final class Version20260527110000_AssetLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle — add lock_version to asset + backfill NULL status to active';
    }

    public function isTransactional(): bool
    {
        return false; // DDL — CLAUDE.md pitfall #6
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('asset')) {
            $this->write('Skipping — asset table not found');
            return;
        }

        $table = $schema->getTable('asset');

        if (!$table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE asset ADD COLUMN lock_version INT NOT NULL DEFAULT 0');
        } else {
            $this->write('Skipping asset.lock_version — column already exists');
        }

        // Backfill: any NULL or empty status defaults to 'active' (existing operational assets)
        $this->addSql("UPDATE asset SET status = 'active' WHERE status IS NULL OR status = ''");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('asset')) {
            return;
        }

        $table = $schema->getTable('asset');

        if ($table->hasColumn('lock_version')) {
            $this->addSql('ALTER TABLE asset DROP COLUMN lock_version');
        }
    }
}
