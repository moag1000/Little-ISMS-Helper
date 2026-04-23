<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8QW-3 — SystemSettings seed: document.default_classification.
 *
 * Inserts the default document classification setting (value: 'internal').
 * Idempotent: uses INSERT WHERE NOT EXISTS so migration can run safely
 * on environments where the setting was already created manually.
 *
 * Admin UI: the existing /admin/system-settings/edit?category=document page
 * renders this automatically (no new controller needed).
 */
final class Version20260421310000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8QW-3: system_settings seed document.default_classification = internal';
    }

    public function up(Schema $schema): void
    {
        // value column is JSON type — wrap string in JSON quotes
        $this->addSql("
            INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, updated_by, created_at, updated_at)
            SELECT 'document', 'default_classification', '\"internal\"', 0,
                   'Default information classification applied when uploading a new document (public, internal, confidential, strictly_confidential)',
                   NULL, NOW(), NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM system_settings
                WHERE category = 'document' AND setting_key = 'default_classification'
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE FROM system_settings
            WHERE category = 'document' AND setting_key = 'default_classification'
        ");
    }
}
