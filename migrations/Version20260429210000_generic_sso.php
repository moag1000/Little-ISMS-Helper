<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generic SSO (OIDC/OAuth2):
 *  - identity_provider (global if tenant_id NULL, else tenant-scoped)
 *  - sso_user_approval (JIT approval queue)
 *  - users.sso_external_id + users.sso_provider_id
 */
final class Version20260429210000_generic_sso extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generic SSO: identity_provider, sso_user_approval, users.sso_external_id/sso_provider_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS identity_provider (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            type VARCHAR(32) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            client_id VARCHAR(255) NOT NULL,
            client_secret_encrypted LONGTEXT DEFAULT NULL,
            discovery_url VARCHAR(512) DEFAULT NULL,
            issuer VARCHAR(512) DEFAULT NULL,
            authorization_endpoint VARCHAR(512) DEFAULT NULL,
            token_endpoint VARCHAR(512) DEFAULT NULL,
            userinfo_endpoint VARCHAR(512) DEFAULT NULL,
            jwks_uri VARCHAR(512) DEFAULT NULL,
            scopes JSON NOT NULL,
            attribute_map JSON NOT NULL,
            button_label VARCHAR(128) DEFAULT NULL,
            button_icon VARCHAR(64) DEFAULT NULL,
            button_color VARCHAR(32) DEFAULT NULL,
            domain_bindings JSON NOT NULL,
            domain_binding_mode VARCHAR(16) NOT NULL DEFAULT \'optional\',
            jit_provisioning TINYINT(1) NOT NULL DEFAULT 1,
            auto_approve TINYINT(1) NOT NULL DEFAULT 0,
            default_role VARCHAR(32) NOT NULL DEFAULT \'ROLE_USER\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE KEY uniq_idp_slug_tenant (slug, tenant_id),
            INDEX idx_idp_tenant (tenant_id),
            PRIMARY KEY(id),
            CONSTRAINT fk_idp_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS sso_user_approval (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            provider_id INT NOT NULL,
            reviewed_by_id INT DEFAULT NULL,
            email VARCHAR(180) NOT NULL,
            external_id VARCHAR(255) NOT NULL,
            claims JSON NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            reject_reason LONGTEXT DEFAULT NULL,
            INDEX idx_ssoa_status (status),
            INDEX idx_ssoa_provider_email (provider_id, email),
            INDEX idx_ssoa_tenant (tenant_id),
            INDEX idx_ssoa_reviewed_by (reviewed_by_id),
            PRIMARY KEY(id),
            CONSTRAINT fk_ssoa_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
            CONSTRAINT fk_ssoa_provider FOREIGN KEY (provider_id) REFERENCES identity_provider (id) ON DELETE CASCADE,
            CONSTRAINT fk_ssoa_reviewed_by FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add SSO columns to users — guarded against re-run via information_schema lookup.
        $hasExt = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sso_external_id'"
        );
        if ($hasExt === 0) {
            $this->addSql('ALTER TABLE users ADD sso_external_id VARCHAR(255) DEFAULT NULL');
        }

        $hasProv = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sso_provider_id'"
        );
        if ($hasProv === 0) {
            $this->addSql('ALTER TABLE users ADD sso_provider_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE users ADD CONSTRAINT fk_users_sso_provider FOREIGN KEY (sso_provider_id) REFERENCES identity_provider (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX idx_users_sso_provider ON users (sso_provider_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $hasFk = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_sso_provider'"
        );
        if ($hasFk > 0) {
            $this->addSql('ALTER TABLE users DROP FOREIGN KEY fk_users_sso_provider');
        }
        $hasIdx = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_sso_provider'"
        );
        if ($hasIdx > 0) {
            $this->addSql('DROP INDEX idx_users_sso_provider ON users');
        }
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS sso_provider_id');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS sso_external_id');
        $this->addSql('DROP TABLE IF EXISTS sso_user_approval');
        $this->addSql('DROP TABLE IF EXISTS identity_provider');
    }
}
