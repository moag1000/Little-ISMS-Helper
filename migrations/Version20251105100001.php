<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default system roles and permissions for RBAC';
    }

    public function up(Schema $schema): void
    {
        // NOTE: Tables are created by Version20251105000004, this migration only adds default data

        // Insert default system roles
        $this->addSql("INSERT IGNORE INTO roles (name, description, is_system_role, created_at) VALUES
            ('ROLE_SUPER_ADMIN', 'Super Administrator with full system access', 1, NOW()),
            ('ROLE_ADMIN', 'Administrator', 1, NOW()),
            ('ROLE_MANAGER', 'Manager with extended permissions', 1, NOW()),
            ('ROLE_AUDITOR', 'Auditor with read access', 1, NOW()),
            ('ROLE_USER', 'Regular user', 1, NOW())");

        // Insert default system permissions
        $this->addSql("INSERT IGNORE INTO permissions (name, description, category, action, is_system_permission, created_at) VALUES
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
        // Delete default data (tables are managed by Version20251105000004)
        $this->addSql('DELETE FROM permissions WHERE is_system_permission = 1');
        $this->addSql('DELETE FROM roles WHERE is_system_role = 1');
    }
}
