<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507193103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tier-1 Compliance Settings on Tenant: locale, timezone, financial-year, TLP default, DPO contact, supervisory authorities, data-retention policies';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant
            ADD COLUMN locale VARCHAR(10) DEFAULT 'de_DE',
            ADD COLUMN timezone VARCHAR(50) DEFAULT 'Europe/Berlin',
            ADD COLUMN financial_year_start_month SMALLINT DEFAULT 1 COMMENT 'Financial year start month 1-12',
            ADD COLUMN tlp_default VARCHAR(16) DEFAULT 'amber' COMMENT 'TLP default for incident sharing: clear|green|amber|red',
            ADD COLUMN dpo_contact_name VARCHAR(255) DEFAULT NULL,
            ADD COLUMN dpo_contact_email VARCHAR(255) DEFAULT NULL,
            ADD COLUMN supervisory_authorities JSON DEFAULT NULL COMMENT 'Supervisory authorities map keyed by jurisdiction/role (BSI, BaFin, LDA, CSIRT-bund etc.)',
            ADD COLUMN data_retention_policies JSON DEFAULT NULL COMMENT 'GDPR retention policies keyed by data category'");

        // Seed default global SystemSettings (security policies)
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ([
            ['security', 'mfa_required_roles', '["ROLE_ADMIN","ROLE_SUPER_ADMIN","ROLE_GROUP_CISO"]', 'Roles that must have MFA enrolled (NIS2 21.2.j + DORA Art. 9)'],
            ['security', 'password_min_length', '12', 'Minimum password length per BSI Stand der Technik'],
            ['security', 'password_require_complexity', 'true', 'Require uppercase + lowercase + digit + special'],
            ['security', 'password_rotation_days', '0', 'Rotation interval (0 = no rotation per NIST SP 800-63B; >0 days only on compromise indicator)'],
            ['security', 'session_timeout_minutes', '60', 'Idle session timeout in minutes (DSGVO Art. 32 + ISO A.8.5)'],
        ] as $setting) {
            [$category, $key, $value, $description] = $setting;
            $valueQ = $this->connection->quote($value);
            $descQ = $this->connection->quote($description);
            $catQ = $this->connection->quote($category);
            $keyQ = $this->connection->quote($key);
            $nowQ = $this->connection->quote($now);
            $this->addSql("
                INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, created_at, updated_at, updated_by)
                SELECT {$catQ}, {$keyQ}, {$valueQ}, 0, {$descQ}, {$nowQ}, {$nowQ}, 'system'
                WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE category={$catQ} AND setting_key={$keyQ})
            ");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant
            DROP COLUMN locale,
            DROP COLUMN timezone,
            DROP COLUMN financial_year_start_month,
            DROP COLUMN tlp_default,
            DROP COLUMN dpo_contact_name,
            DROP COLUMN dpo_contact_email,
            DROP COLUMN supervisory_authorities,
            DROP COLUMN data_retention_policies");
        $this->addSql("DELETE FROM system_settings WHERE category='security' AND setting_key IN ('mfa_required_roles','password_min_length','password_require_complexity','password_rotation_days','session_timeout_minutes')");
    }
}
