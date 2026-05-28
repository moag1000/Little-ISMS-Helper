<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AuditProgram entity — ISO 19011 §5.4 documented Audit Programme.
 *
 * DDL changes:
 * 1. CREATE TABLE audit_program
 * 2. CREATE TABLE audit_program_compliance_framework (M2M join)
 * 3. ALTER TABLE internal_audit ADD COLUMN program_id (FK to audit_program, nullable)
 *
 * NOTE: isTransactional()=false per CLAUDE.md Pitfall 6 — DDL migrations need
 * this to avoid SAVEPOINT failures when >1 migration runs in a single `migrate` call.
 */
final class Version20260528120000_AddAuditProgramEntity extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add AuditProgram entity (ISO 19011 §5.4 Jahresplan) with M2M frameworks and relation to internal_audit';
    }

    public function up(Schema $schema): void
    {
        // 1. audit_program table
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_program (
                id                        INT AUTO_INCREMENT NOT NULL,
                tenant_id                 INT          NOT NULL,
                responsible_person_id     INT          DEFAULT NULL,
                responsible_person_ref_id INT          DEFAULT NULL,
                approved_by_id            INT          DEFAULT NULL,
                created_by_id             INT          DEFAULT NULL,
                name                      VARCHAR(200) NOT NULL,
                year                      INT          NOT NULL,
                scope                     LONGTEXT     DEFAULT NULL,
                objectives                LONGTEXT     DEFAULT NULL,
                status                    VARCHAR(30)  NOT NULL DEFAULT 'draft',
                budget                    DECIMAL(12,2) DEFAULT NULL,
                notes                     LONGTEXT     DEFAULT NULL,
                approved_at               DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at                DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at                DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                lock_version              INT          NOT NULL DEFAULT 0,
                INDEX idx_audit_program_tenant (tenant_id),
                INDEX idx_audit_program_year (year),
                INDEX idx_audit_program_status (status),
                INDEX idx_audit_program_responsible (responsible_person_id),
                INDEX idx_audit_program_approved_by (approved_by_id),
                INDEX idx_audit_program_created_by (created_by_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 2. FKs on audit_program
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_program
                ADD CONSTRAINT fk_audit_program_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_audit_program_responsible_person
                    FOREIGN KEY (responsible_person_id) REFERENCES user (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_audit_program_responsible_person_ref
                    FOREIGN KEY (responsible_person_ref_id) REFERENCES person (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_audit_program_approved_by
                    FOREIGN KEY (approved_by_id) REFERENCES user (id) ON DELETE SET NULL,
                ADD CONSTRAINT fk_audit_program_created_by
                    FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);

        // 3. M2M join table: audit_program_compliance_framework
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_program_compliance_framework (
                audit_program_id       INT NOT NULL,
                compliance_framework_id INT NOT NULL,
                INDEX idx_apf_program (audit_program_id),
                INDEX idx_apf_framework (compliance_framework_id),
                PRIMARY KEY (audit_program_id, compliance_framework_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE audit_program_compliance_framework
                ADD CONSTRAINT fk_apf_audit_program
                    FOREIGN KEY (audit_program_id) REFERENCES audit_program (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_apf_compliance_framework
                    FOREIGN KEY (compliance_framework_id) REFERENCES compliance_framework (id) ON DELETE CASCADE
        SQL);

        // 4. Link existing internal_audit rows to audit_program
        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD COLUMN program_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD INDEX idx_internal_audit_program (program_id),
                ADD CONSTRAINT fk_internal_audit_program
                    FOREIGN KEY (program_id) REFERENCES audit_program (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop FK and column from internal_audit first
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY fk_internal_audit_program');
        $this->addSql('ALTER TABLE internal_audit DROP INDEX idx_internal_audit_program');
        $this->addSql('ALTER TABLE internal_audit DROP COLUMN program_id');

        // Drop M2M join table
        $this->addSql('ALTER TABLE audit_program_compliance_framework DROP FOREIGN KEY fk_apf_audit_program');
        $this->addSql('ALTER TABLE audit_program_compliance_framework DROP FOREIGN KEY fk_apf_compliance_framework');
        $this->addSql('DROP TABLE audit_program_compliance_framework');

        // Drop main table (FKs dropped automatically)
        $this->addSql('ALTER TABLE audit_program DROP FOREIGN KEY fk_audit_program_tenant');
        $this->addSql('ALTER TABLE audit_program DROP FOREIGN KEY fk_audit_program_responsible_person');
        $this->addSql('ALTER TABLE audit_program DROP FOREIGN KEY fk_audit_program_responsible_person_ref');
        $this->addSql('ALTER TABLE audit_program DROP FOREIGN KEY fk_audit_program_approved_by');
        $this->addSql('ALTER TABLE audit_program DROP FOREIGN KEY fk_audit_program_created_by');
        $this->addSql('DROP TABLE audit_program');
    }
}
