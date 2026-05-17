<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517100100_AddLockVersionToDocument extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lifecycle Foundation Pilot — @Version column on documents for optimistic locking';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Defensive: an earlier migration may have been recorded as executed
        // without running its DDL (CLAUDE.md pitfall #6 — PREPARE/EXECUTE pattern).
        // Skip rather than fail if the `documents` table is genuinely missing —
        // schema:reconcile creates it later before this column is needed.
        if (!$schema->hasTable('documents')) {
            $this->warnIf(true, 'Skipping lock_version ADD: documents table missing.');
            return;
        }
        if ($schema->getTable('documents')->hasColumn('lock_version')) {
            $this->warnIf(true, 'Skipping lock_version ADD: column already present.');
            return;
        }
        $this->addSql('ALTER TABLE documents ADD COLUMN lock_version INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('documents') || !$schema->getTable('documents')->hasColumn('lock_version')) {
            return;
        }
        $this->addSql('ALTER TABLE documents DROP COLUMN lock_version');
    }
}
