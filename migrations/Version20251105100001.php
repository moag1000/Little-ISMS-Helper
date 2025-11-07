<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create User, Role, and Permission tables for authentication and RBAC';
    }

    public function up(Schema $schema): void
    {
        // Users table
        $this->addSql('CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) DEFAULT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            auth_provider VARCHAR(20) DEFAULT NULL,
            azure_object_id VARCHAR(255) DEFAULT NULL,
            azure_tenant_id VARCHAR(255) DEFAULT NULL,
            azure_metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            department VARCHAR(255) DEFAULT NULL,
            job_title VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(50) DEFAULT NULL,
            profile_picture LONGTEXT DEFAULT NULL,
            language VARCHAR(10) DEFAULT \'de\',
            timezone VARCHAR(50) DEFAULT \'Europe/Berlin\',
            UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email),
            UNIQUE INDEX UNIQ_AZURE_OBJECT_ID (azure_object_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Roles table
        $this->addSql('CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            is_system_role TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_NAME (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Permissions table
        $this->addSql('CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            resource VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            is_system_permission TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_NAME (name),
            UNIQUE INDEX UNIQ_RESOURCE_ACTION (resource, action),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // User-Role junction table
        $this->addSql('CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            INDEX IDX_USER (user_id),
            INDEX IDX_ROLE (role_id),
            PRIMARY KEY(user_id, role_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE user_roles
            ADD CONSTRAINT FK_UR_USER FOREIGN KEY (user_id)
            REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE user_roles
            ADD CONSTRAINT FK_UR_ROLE FOREIGN KEY (role_id)
            REFERENCES roles (id) ON DELETE CASCADE');

        // Role-Permission junction table
        $this->addSql('CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            INDEX IDX_ROLE (role_id),
            INDEX IDX_PERMISSION (permission_id),
            PRIMARY KEY(role_id, permission_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE role_permissions
            ADD CONSTRAINT FK_RP_ROLE FOREIGN KEY (role_id)
            REFERENCES roles (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE role_permissions
            ADD CONSTRAINT FK_RP_PERMISSION FOREIGN KEY (permission_id)
            REFERENCES permissions (id) ON DELETE CASCADE');

        // Insert default system roles
        $this->addSql("INSERT IGNORE INTO roles (name, description, is_system_role, created_at) VALUES
            ('ROLE_SUPER_ADMIN', 'Super Administrator with full system access', 1, NOW()),
            ('ROLE_ADMIN', 'Administrator', 1, NOW()),
            ('ROLE_MANAGER', 'Manager with extended permissions', 1, NOW()),
            ('ROLE_AUDITOR', 'Auditor with read access', 1, NOW()),
            ('ROLE_USER', 'Regular user', 1, NOW())");

        // Insert default system permissions
        $this->addSql("INSERT IGNORE INTO permissions (name, description, resource, action, is_system_permission, created_at) VALUES
            ('asset.view', 'View assets', 'asset', 'view', 1, NOW()),
            ('asset.create', 'Create assets', 'asset', 'create', 1, NOW()),
            ('asset.edit', 'Edit assets', 'asset', 'edit', 1, NOW()),
            ('asset.delete', 'Delete assets', 'asset', 'delete', 1, NOW()),
            ('risk.view', 'View risks', 'risk', 'view', 1, NOW()),
            ('risk.create', 'Create risks', 'risk', 'create', 1, NOW()),
            ('risk.edit', 'Edit risks', 'risk', 'edit', 1, NOW()),
            ('risk.delete', 'Delete risks', 'risk', 'delete', 1, NOW()),
            ('control.view', 'View controls', 'control', 'view', 1, NOW()),
            ('control.create', 'Create controls', 'control', 'create', 1, NOW()),
            ('control.edit', 'Edit controls', 'control', 'edit', 1, NOW()),
            ('control.delete', 'Delete controls', 'control', 'delete', 1, NOW()),
            ('incident.view', 'View incidents', 'incident', 'view', 1, NOW()),
            ('incident.create', 'Create incidents', 'incident', 'create', 1, NOW()),
            ('incident.edit', 'Edit incidents', 'incident', 'edit', 1, NOW()),
            ('incident.delete', 'Delete incidents', 'incident', 'delete', 1, NOW()),
            ('audit.view', 'View audits', 'audit', 'view', 1, NOW()),
            ('audit.create', 'Create audits', 'audit', 'create', 1, NOW()),
            ('audit.edit', 'Edit audits', 'audit', 'edit', 1, NOW()),
            ('audit.delete', 'Delete audits', 'audit', 'delete', 1, NOW()),
            ('user.view', 'View users', 'user', 'view', 1, NOW()),
            ('user.create', 'Create users', 'user', 'create', 1, NOW()),
            ('user.edit', 'Edit users', 'user', 'edit', 1, NOW()),
            ('user.delete', 'Delete users', 'user', 'delete', 1, NOW()),
            ('role.view', 'View roles', 'role', 'view', 1, NOW()),
            ('role.create', 'Create roles', 'role', 'create', 1, NOW()),
            ('role.edit', 'Edit roles', 'role', 'edit', 1, NOW()),
            ('role.delete', 'Delete roles', 'role', 'delete', 1, NOW()),
            ('audit_log.view', 'View audit logs', 'audit_log', 'view', 1, NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_UR_USER');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_UR_ROLE');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_RP_ROLE');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_RP_PERMISSION');

        $this->addSql('DROP TABLE IF EXISTS role_permissions');
        $this->addSql('DROP TABLE IF EXISTS user_roles');
        $this->addSql('DROP TABLE IF EXISTS permissions');
        $this->addSql('DROP TABLE IF EXISTS roles');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
