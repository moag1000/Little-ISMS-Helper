<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corporate Audit Scope Migration
 *
 * Adds support for corporate-wide and subsidiary-specific audits
 */
final class Version20250113000003_corporate_audit_scope extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add corporate audit scope support with subsidiary tracking';
    }

    public function up(Schema $schema): void
    {
        // Create join table for audit-subsidiary relationships
        $this->addSql('CREATE TABLE IF NOT EXISTS internal_audit_subsidiary (
            internal_audit_id INT NOT NULL,
            tenant_id INT NOT NULL,
            PRIMARY KEY(internal_audit_id, tenant_id),
            INDEX IDX_audit_subsidiary_audit (internal_audit_id),
            INDEX IDX_audit_subsidiary_tenant (tenant_id),
            CONSTRAINT FK_audit_subsidiary_audit
                FOREIGN KEY (internal_audit_id)
                REFERENCES internal_audit (id)
                ON DELETE CASCADE,
            CONSTRAINT FK_audit_subsidiary_tenant
                FOREIGN KEY (tenant_id)
                REFERENCES tenant (id)
                ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Drop join table
        $this->addSql('DROP TABLE IF EXISTS internal_audit_subsidiary');
    }
}
