<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2.5 — AuditProgram entity (ISO 19011 §5.4).
 *
 * Creates `audit_program` table + nullable FK `audit_program_id` on `internal_audit`.
 * DDL migration — isTransactional() = false (MySQL implicit commit).
 */
final class Version20260628100000_AuditProgramEntity extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2.5: AuditProgram entity (ISO 19011 §5.4) + FK on internal_audit';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_program (
                id                  INT NOT NULL AUTO_INCREMENT,
                tenant_id           INT NOT NULL,
                programme_owner_id  INT DEFAULT NULL,
                created_by_id       INT DEFAULT NULL,
                name                VARCHAR(255) NOT NULL,
                description         LONGTEXT DEFAULT NULL,
                objectives          LONGTEXT DEFAULT NULL,
                scope               LONGTEXT DEFAULT NULL,
                start_date          DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                end_date            DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                status              VARCHAR(30) NOT NULL DEFAULT 'planning',
                risk_categories     JSON DEFAULT NULL,
                frequency           VARCHAR(50) DEFAULT NULL,
                created_at          DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at          DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                archived_at         DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                lock_version        INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                INDEX idx_audit_program_tenant (tenant_id),
                INDEX idx_audit_program_status (status),
                CONSTRAINT fk_ap_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_ap_owner
                    FOREIGN KEY (programme_owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_ap_created_by
                    FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(
            'ALTER TABLE internal_audit ADD COLUMN IF NOT EXISTS audit_program_id INT DEFAULT NULL'
        );

        $this->addSql(
            'ALTER TABLE internal_audit ADD CONSTRAINT fk_ia_audit_program FOREIGN KEY (audit_program_id) REFERENCES audit_program (id) ON DELETE SET NULL'
        );

        $this->addSql(
            'ALTER TABLE internal_audit ADD INDEX idx_ia_audit_program (audit_program_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY fk_ia_audit_program');
        $this->addSql('ALTER TABLE internal_audit DROP INDEX idx_ia_audit_program');
        $this->addSql('ALTER TABLE internal_audit DROP COLUMN audit_program_id');
        $this->addSql('DROP TABLE IF EXISTS audit_program');
    }
}
