<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F1 SSO Wave 2 — CREATE identity_provider_role_mapping + identity_provider_user_mapping.
 *
 * No PREPARE/EXECUTE — plain DDL only.
 * isTransactional()=false required: MySQL implicit DDL commits break
 * Doctrine's SAVEPOINT strategy in multi-migration runs.
 */
final class Version20260512220000_f1_sso_wave2_role_user_mapping extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'F1 SSO Wave 2 — role-mapping + user-mapping tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS identity_provider_role_mapping (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT DEFAULT NULL,
            identity_provider_id INT NOT NULL,
            claim_key VARCHAR(128) NOT NULL,
            claim_value_expression VARCHAR(255) NOT NULL,
            assigned_role VARCHAR(64) NOT NULL DEFAULT \'ROLE_USER\',
            assigned_permissions JSON NOT NULL,
            priority INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            audit_description VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_iprm_idp_priority (identity_provider_id, priority),
            INDEX idx_iprm_tenant (tenant_id),
            CONSTRAINT fk_iprm_idp FOREIGN KEY (identity_provider_id)
                REFERENCES identity_provider (id) ON DELETE CASCADE,
            CONSTRAINT fk_iprm_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS identity_provider_user_mapping (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT DEFAULT NULL,
            identity_provider_id INT NOT NULL,
            user_id INT NOT NULL,
            idp_user_id VARCHAR(255) NOT NULL,
            idp_claims_snapshot JSON DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            first_logged_in_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            successful_login_count INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_ipum_idp_sub (identity_provider_id, idp_user_id),
            INDEX idx_ipum_user (user_id),
            INDEX idx_ipum_tenant (tenant_id),
            PRIMARY KEY (id),
            CONSTRAINT fk_ipum_idp FOREIGN KEY (identity_provider_id)
                REFERENCES identity_provider (id) ON DELETE CASCADE,
            CONSTRAINT fk_ipum_user FOREIGN KEY (user_id)
                REFERENCES `users` (id) ON DELETE CASCADE,
            CONSTRAINT fk_ipum_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS identity_provider_user_mapping');
        $this->addSql('DROP TABLE IF EXISTS identity_provider_role_mapping');
    }
}
