<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 * Migration for User, Role, and Permission entities
 */
final class Version20251105000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, roles, and permissions tables with Azure AD integration';
    }

    public function up(Schema $schema): void
    {
        // Users table
        $this->addSql('CREATE TABLE users (
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
        $this->addSql('CREATE TABLE roles (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            is_system_role TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_ROLE_NAME (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Permissions table
        $this->addSql('CREATE TABLE permissions (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            category VARCHAR(50) NOT NULL,
            action VARCHAR(50) NOT NULL,
            is_system_permission TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_PERMISSION_NAME (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // User-Role junction table
        $this->addSql('CREATE TABLE user_roles (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            INDEX IDX_USER_ROLES_USER (user_id),
            INDEX IDX_USER_ROLES_ROLE (role_id),
            PRIMARY KEY(user_id, role_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Role-Permission junction table
        $this->addSql('CREATE TABLE role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            INDEX IDX_ROLE_PERMISSIONS_ROLE (role_id),
            INDEX IDX_ROLE_PERMISSIONS_PERMISSION (permission_id),
            PRIMARY KEY(role_id, permission_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign keys
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_USER_ROLES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_USER_ROLES_ROLE FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_ROLE_PERMISSIONS_ROLE FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_ROLE_PERMISSIONS_PERMISSION FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_USER_ROLES_USER');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_USER_ROLES_ROLE');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_ROLE_PERMISSIONS_ROLE');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_ROLE_PERMISSIONS_PERMISSION');

        // Drop tables
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE users');
    }
}
