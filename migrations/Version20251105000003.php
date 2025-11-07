<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hierarchical compliance requirements, audit checklists, and flexible audit scopes';
    }

    public function up(Schema $schema): void
    {
        // Add hierarchical structure to compliance_requirement
        $this->addSql('ALTER TABLE compliance_requirement
            ADD COLUMN requirement_type VARCHAR(50) NOT NULL DEFAULT \'core\',
            ADD COLUMN parent_requirement_id INT DEFAULT NULL');

        $this->addSql('ALTER TABLE compliance_requirement
            ADD CONSTRAINT FK_CR_PARENT FOREIGN KEY (parent_requirement_id)
            REFERENCES compliance_requirement (id) ON DELETE CASCADE');

        $this->addSql('CREATE INDEX IDX_PARENT_REQUIREMENT ON compliance_requirement (parent_requirement_id)');
        $this->addSql('CREATE INDEX IDX_REQUIREMENT_TYPE ON compliance_requirement (requirement_type)');

        // Create audit_checklist table
        $this->addSql('CREATE TABLE audit_checklist (
            id INT AUTO_INCREMENT NOT NULL,
            audit_id INT NOT NULL,
            requirement_id INT NOT NULL,
            verification_status VARCHAR(50) NOT NULL DEFAULT \'not_checked\',
            audit_notes LONGTEXT DEFAULT NULL,
            evidence_found LONGTEXT DEFAULT NULL,
            findings LONGTEXT DEFAULT NULL,
            recommendations LONGTEXT DEFAULT NULL,
            compliance_score INT NOT NULL DEFAULT 0,
            auditor VARCHAR(100) DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_AUDIT (audit_id),
            INDEX IDX_REQUIREMENT (requirement_id),
            INDEX IDX_VERIFICATION_STATUS (verification_status)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE audit_checklist
            ADD CONSTRAINT FK_AC_AUDIT FOREIGN KEY (audit_id)
            REFERENCES internal_audit (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE audit_checklist
            ADD CONSTRAINT FK_AC_REQUIREMENT FOREIGN KEY (requirement_id)
            REFERENCES compliance_requirement (id) ON DELETE CASCADE');

        // Add flexible scope fields to internal_audit
        $this->addSql('ALTER TABLE internal_audit
            ADD COLUMN scope_type VARCHAR(50) DEFAULT \'full_isms\',
            ADD COLUMN scope_details JSON DEFAULT NULL,
            ADD COLUMN scoped_framework_id INT DEFAULT NULL');

        $this->addSql('ALTER TABLE internal_audit
            ADD CONSTRAINT FK_IA_FRAMEWORK FOREIGN KEY (scoped_framework_id)
            REFERENCES compliance_framework (id) ON DELETE SET NULL');

        $this->addSql('CREATE INDEX IDX_SCOPE_TYPE ON internal_audit (scope_type)');
        $this->addSql('CREATE INDEX IDX_SCOPED_FRAMEWORK ON internal_audit (scoped_framework_id)');

        // Create internal_audit_asset many-to-many table
        $this->addSql('CREATE TABLE internal_audit_asset (
            internal_audit_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_AUDIT (internal_audit_id),
            INDEX IDX_ASSET (asset_id),
            PRIMARY KEY(internal_audit_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE internal_audit_asset
            ADD CONSTRAINT FK_IAA_AUDIT FOREIGN KEY (internal_audit_id)
            REFERENCES internal_audit (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE internal_audit_asset
            ADD CONSTRAINT FK_IAA_ASSET FOREIGN KEY (asset_id)
            REFERENCES asset (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS internal_audit_asset');
        $this->addSql('DROP TABLE IF EXISTS audit_checklist');

        $this->addSql('ALTER TABLE internal_audit
            DROP FOREIGN KEY IF EXISTS FK_IA_FRAMEWORK');

        $this->addSql('ALTER TABLE internal_audit
            DROP INDEX IF EXISTS IDX_SCOPE_TYPE,
            DROP INDEX IF EXISTS IDX_SCOPED_FRAMEWORK,
            DROP COLUMN IF EXISTS scope_type,
            DROP COLUMN IF EXISTS scope_details,
            DROP COLUMN IF EXISTS scoped_framework_id');

        $this->addSql('ALTER TABLE compliance_requirement
            DROP FOREIGN KEY IF EXISTS FK_CR_PARENT');

        $this->addSql('ALTER TABLE compliance_requirement
            DROP INDEX IF EXISTS IDX_PARENT_REQUIREMENT,
            DROP INDEX IF EXISTS IDX_REQUIREMENT_TYPE,
            DROP COLUMN IF EXISTS requirement_type,
            DROP COLUMN IF EXISTS parent_requirement_id');
    }
}
