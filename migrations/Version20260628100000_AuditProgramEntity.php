<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2.5 — AuditProgram entity (ISO 19011 §5.4 Audit Programme Management).
 *
 * Creates `audit_program` table and adds nullable FK `audit_program_id`
 * to `internal_audit` table.
 *
 * isTransactional() = false required: MySQL DDL (CREATE TABLE + ALTER TABLE)
 * implicitly commits; Doctrine's SAVEPOINT fails on >1 DDL per migrate run
 * (see CLAUDE.md "DDL migrations isTransactional").
 */
final class Version20260628100000_AuditProgramEntity extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Phase 2.5: Create audit_program table and add audit_program_id FK to internal_audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_program (
                id              INT AUTO_INCREMENT NOT NULL,
                tenant_id       INT NOT NULL,
                programme_owner_id INT DEFAULT NULL,
                created_by_id   INT DEFAULT NULL,
                name            VARCHAR(255) NOT NULL,
                description     LONGTEXT DEFAULT NULL,
                scope           LONGTEXT DEFAULT NULL,
                objectives      LONGTEXT DEFAULT NULL,
                start_date      DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                end_date        DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                status          VARCHAR(30) NOT NULL DEFAULT 'planning',
                risk_categories JSON DEFAULT NULL,
                frequency       VARCHAR(50) DEFAULT NULL,
                created_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at      DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                archived_at     DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                lock_version    INT NOT NULL DEFAULT 0,
                INDEX idx_audit_program_tenant (tenant_id),
                INDEX idx_audit_program_status (status),
                CONSTRAINT fk_audit_program_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id),
                CONSTRAINT fk_audit_program_owner FOREIGN KEY (programme_owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_audit_program_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD COLUMN IF NOT EXISTS audit_program_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD CONSTRAINT fk_internal_audit_program
                FOREIGN KEY IF NOT EXISTS (audit_program_id)
                REFERENCES audit_program (id)
                ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_internal_audit_program
                ON internal_audit (audit_program_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY IF EXISTS fk_internal_audit_program');
        $this->addSql('ALTER TABLE internal_audit DROP INDEX IF EXISTS idx_internal_audit_program');
        $this->addSql('ALTER TABLE internal_audit DROP COLUMN IF EXISTS audit_program_id');
        $this->addSql('DROP TABLE IF EXISTS audit_program');
    }
}
