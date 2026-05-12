<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 4 / F1 SSO Wave 1:
 *   - identity_provider: add preset_type, default_fallback_role, mfa_inheritance
 *   - tenant: add sso_enforced
 *
 * DDL migration → isTransactional() = false (MySQL implicit commit per ALTER TABLE).
 */
final class Version20260515100000_sso_wave1_preset extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sprint 4 / F1 SSO Wave 1: preset_type + default_fallback_role + mfa_inheritance on identity_provider; sso_enforced on tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE identity_provider
            ADD COLUMN preset_type VARCHAR(32) NULL AFTER default_role,
            ADD COLUMN default_fallback_role VARCHAR(64) NULL DEFAULT 'ROLE_USER' AFTER preset_type,
            ADD COLUMN mfa_inheritance VARCHAR(16) NULL DEFAULT 'optional' AFTER default_fallback_role
        ");

        $this->addSql("ALTER TABLE tenant
            ADD COLUMN sso_enforced TINYINT(1) NOT NULL DEFAULT 0 AFTER api_rate_limit_per_minute
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_provider
            DROP COLUMN preset_type,
            DROP COLUMN default_fallback_role,
            DROP COLUMN mfa_inheritance
        ');

        $this->addSql('ALTER TABLE tenant DROP COLUMN sso_enforced');
    }
}
