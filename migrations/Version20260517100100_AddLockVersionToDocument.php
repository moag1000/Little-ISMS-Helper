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
        // Skip rather than fail if the `document` table is genuinely missing —
        // schema:reconcile creates it later before this column is needed.
        if (!$schema->hasTable('document')) {
            $this->warnIf(true, 'Skipping lock_version ADD: document table missing.');
            return;
        }
        if ($schema->getTable('document')->hasColumn('lock_version')) {
            $this->warnIf(true, 'Skipping lock_version ADD: column already present.');
            return;
        }
        $this->addSql('ALTER TABLE document ADD COLUMN lock_version INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('document') || !$schema->getTable('document')->hasColumn('lock_version')) {
            return;
        }
        $this->addSql('ALTER TABLE document DROP COLUMN lock_version');
    }
}
