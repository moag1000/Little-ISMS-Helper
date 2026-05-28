<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TISAX BYO VDA-ISA Import Wizard — Phase 1 DDL.
 *
 * Adds two nullable columns to `compliance_requirement`:
 *   - requirement_source  VARCHAR(20) NULL  — 'system' | 'tenant_upload'
 *   - tenant_id           INT NULL          — tenant FK for tenant-scoped requirements
 *     (nullable so existing system rows stay un-scoped)
 *
 * Also creates the `tisax_license_confirmation` log table used to
 * record user acceptance of the ENX licence obligation before each
 * workbook upload.
 *
 * isTransactional()=false required: MySQL DDL auto-commits, which
 * invalidates Doctrine's SAVEPOINT on multi-migration runs.
 */
final class Version20260618100000_TisaxRequirementSource extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'TISAX BYO import wizard: add requirement_source + tenant_id to compliance_requirement; create tisax_license_confirmation table';
    }

    public function up(Schema $schema): void
    {
        // 1. Add requirement_source discriminator column (NULL = legacy 'system')
        $this->addSql(
            "ALTER TABLE compliance_requirement
             ADD COLUMN IF NOT EXISTS requirement_source VARCHAR(20) NULL DEFAULT 'system'
             COMMENT 'Discriminator: system | tenant_upload'",
        );

        // 2. Add nullable tenant_id FK for tenant-scoped uploaded requirements
        $this->addSql(
            'ALTER TABLE compliance_requirement
             ADD COLUMN IF NOT EXISTS upload_tenant_id INT NULL
             COMMENT "Tenant that uploaded this requirement (NULL = global system row)"',
        );

        // 3. Foreign key on upload_tenant_id → tenant.id
        $this->addSql(
            'ALTER TABLE compliance_requirement
             ADD CONSTRAINT fk_cr_upload_tenant
             FOREIGN KEY IF NOT EXISTS (upload_tenant_id) REFERENCES tenant (id) ON DELETE SET NULL',
        );

        // 4. Index for tenant-scoped lookups
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_cr_upload_tenant
             ON compliance_requirement (upload_tenant_id)',
        );

        // 5. Tisax licence confirmation audit log
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS tisax_license_confirmation (
                id          INT AUTO_INCREMENT NOT NULL,
                tenant_id   INT NOT NULL,
                user_id     INT NOT NULL,
                confirmed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                workbook_filename VARCHAR(255) NOT NULL,
                ip_address  VARCHAR(45) NOT NULL,
                session_token VARCHAR(64) NOT NULL COMMENT \'SHA-256 of session_id — avoids storing raw token\',
                PRIMARY KEY (id),
                INDEX idx_tlc_tenant (tenant_id),
                INDEX idx_tlc_user   (user_id),
                INDEX idx_tlc_confirmed_at (confirmed_at),
                CONSTRAINT fk_tlc_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_tlc_user   FOREIGN KEY (user_id)   REFERENCES user   (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP FOREIGN KEY IF EXISTS fk_cr_upload_tenant');
        $this->addSql('ALTER TABLE compliance_requirement DROP INDEX IF EXISTS idx_cr_upload_tenant');
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN IF EXISTS upload_tenant_id');
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN IF EXISTS requirement_source');
        $this->addSql('DROP TABLE IF EXISTS tisax_license_confirmation');
    }
}
