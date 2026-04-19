<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 9.P2.4 — Konzern-Audit-Programm.
 *
 * Adds a self-referencing parent_audit_id column to internal_audit so
 * a Holding-CISO can spawn N Tochter-audits from one program template
 * and roll up findings across the subtree afterwards.
 *
 * ON DELETE SET NULL: if the program audit is deleted later, the
 * derived subsidiary audits survive — they are independent records
 * from an audit-trail perspective.
 *
 * `ADD COLUMN IF NOT EXISTS` / `ADD CONSTRAINT IF NOT EXISTS` keeps
 * the migration idempotent on repeat.
 */
final class Version20260420140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Internal-audit parent_audit_id for Konzern-Audit-Programm (P2.4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internal_audit ADD COLUMN IF NOT EXISTS parent_audit_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_parent ON internal_audit (parent_audit_id)');
        // MySQL doesn't support "IF NOT EXISTS" on ADD CONSTRAINT; guard via INFORMATION_SCHEMA.
        $this->addSql("SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'internal_audit' AND CONSTRAINT_NAME = 'fk_audit_parent')");
        $this->addSql("SET @sql := IF(@fk_exists = 0, 'ALTER TABLE internal_audit ADD CONSTRAINT fk_audit_parent FOREIGN KEY (parent_audit_id) REFERENCES internal_audit (id) ON DELETE SET NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'internal_audit' AND CONSTRAINT_NAME = 'fk_audit_parent')");
        $this->addSql("SET @sql := IF(@fk_exists > 0, 'ALTER TABLE internal_audit DROP FOREIGN KEY fk_audit_parent', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
        $this->addSql('DROP INDEX IF EXISTS idx_audit_parent ON internal_audit');
        $this->addSql('ALTER TABLE internal_audit DROP COLUMN IF EXISTS parent_audit_id');
    }
}
