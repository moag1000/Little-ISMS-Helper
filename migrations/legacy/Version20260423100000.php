<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8L.F4 — Seed SystemSettings-Eintrag "audit.retention_days".
 *
 * Default 730 Tage (ISO 27001 Clause 9.1, zweijährige Archivierung).
 * Min 365 (NIS2 Art. 21.2) wird im AuditLogCleanupCommand enforced.
 * Früher in services.yaml als app.audit_log_retention_days Parameter.
 */
final class Version20260423100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8L.F4: seed SystemSettings audit.retention_days (730 days)';
    }

    public function up(Schema $schema): void
    {
        // Spalte heißt 'setting_key' (siehe SystemSettings-Entity); 'value' ist JSON-Column
        // → 730 als JSON-Integer-Literal.
        $this->addSql("
            INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, created_at, updated_at)
            SELECT 'audit', 'retention_days', '730', 0,
                   'Audit-Log Retention in Tagen. Min 365 (NIS2 Art. 21.2), Default 730 (ISO 27001 Clause 9.1). Editierbar unter /admin/audit-log/retention.',
                   NOW(), NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM (SELECT 1 FROM system_settings WHERE category = 'audit' AND setting_key = 'retention_days') AS existing
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM system_settings WHERE category = 'audit' AND setting_key = 'retention_days'");
    }
}
