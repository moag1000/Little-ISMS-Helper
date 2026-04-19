<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 9.P2.3 — Incident cross-posting opt-out flag.
 *
 * Adds `incident.visible_to_holding BOOL NOT NULL DEFAULT TRUE`.
 * Default true: every existing incident becomes visible to the Group-CISO
 * / Konzern-Krisenstab in a holding subtree from the moment this lands,
 * matching the "coordinate group response by default, opt-out for
 * confidential cases" design. Standalone tenants are unaffected
 * because the flag is only consulted by the cross-tenant voter path.
 *
 * `ADD COLUMN IF NOT EXISTS` keeps the migration idempotent on repeat.
 */
final class Version20260420120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incident cross-posting opt-out flag (P2.3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD COLUMN IF NOT EXISTS visible_to_holding TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP COLUMN IF EXISTS visible_to_holding');
    }
}
