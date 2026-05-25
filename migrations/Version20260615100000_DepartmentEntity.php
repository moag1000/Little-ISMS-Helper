<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S18 B3 — Department / OrgUnit foundation entity.
 *
 * Replaces freetext `processing_activity.responsible_department` (TEXT) with a
 * structured FK to a new tenant-scoped `department` table. Legacy text column
 * is retained for zero-data-loss migration (consumers can backfill in a
 * follow-up sprint).
 *
 * `isTransactional() = false` per CLAUDE.md pitfall #6 (multi-DDL ALTER TABLE
 * + CREATE TABLE in one migration run).
 */
final class Version20260615100000_DepartmentEntity extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S18 B3: Department entity (foundation) + ProcessingActivity FK link.';
    }

    public function up(Schema $schema): void
    {
        // 1) Create department table (self-referential parent FK + tenant scope).
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS department (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                parent_id INT DEFAULT NULL,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(20) DEFAULT NULL,
                description LONGTEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_department_tenant (tenant_id),
                INDEX idx_department_parent (parent_id),
                UNIQUE KEY uniq_dept_name_per_tenant (tenant_id, name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 2) Foreign keys for department.
        $this->addSql(<<<'SQL'
            ALTER TABLE department
            ADD CONSTRAINT FK_department_tenant
            FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE department
            ADD CONSTRAINT FK_department_parent
            FOREIGN KEY (parent_id) REFERENCES department (id) ON DELETE SET NULL
        SQL);

        // 3) Add nullable FK column on processing_activity. Legacy `responsible_department`
        //    TEXT column is intentionally kept (marked @deprecated in entity phpdoc).
        $this->addSql(<<<'SQL'
            ALTER TABLE processing_activity
            ADD COLUMN IF NOT EXISTS responsible_department_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE processing_activity
            ADD CONSTRAINT FK_pa_responsible_department
            FOREIGN KEY (responsible_department_id) REFERENCES department (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_pa_responsible_department ON processing_activity (responsible_department_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_pa_responsible_department');
        $this->addSql('DROP INDEX IDX_pa_responsible_department ON processing_activity');
        $this->addSql('ALTER TABLE processing_activity DROP COLUMN responsible_department_id');
        $this->addSql('DROP TABLE department');
    }
}
