<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F44 — Inbound Security-Questionnaire Answer Library.
 *
 * Creates the `answer_library_entry` table.
 *
 * Design notes:
 *   - isTransactional()=false: DDL (CREATE TABLE / ALTER TABLE) commits
 *     implicitly under MySQL — keeping outside the per-migration SAVEPOINT
 *     prevents "SAVEPOINT DOCTRINE_X does not exist" on multi-DDL migrate runs
 *     (CLAUDE.md pitfall #6).
 *   - No PREPARE/EXECUTE — plain DDL only (CLAUDE.md pitfall #6).
 *   - lock_version for optimistic-locking (P-4b).
 *   - tags JSON column for flexible tagging without a join table.
 *   - down() fully reverses (FK constraints first, then table).
 *
 * ISO 27001 Cl. 7.5 — reusable documented information.
 */
final class Version20260706100000_answer_library extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F44 — create answer_library_entry table';
    }

    /**
     * DDL (CREATE TABLE) commits implicitly under MySQL.
     * Must run outside the Doctrine per-migration SAVEPOINT.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS answer_library_entry (
                id            INT AUTO_INCREMENT NOT NULL,
                tenant_id     INT NOT NULL,
                created_by_id INT DEFAULT NULL,
                question      LONGTEXT NOT NULL,
                answer        LONGTEXT NOT NULL,
                category      VARCHAR(40)  NOT NULL DEFAULT 'general',
                tags          JSON         NOT NULL COMMENT '(DC2Type:json)',
                last_used_at  DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                use_count     INT UNSIGNED NOT NULL DEFAULT 0,
                created_at    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at    DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                lock_version  INT          NOT NULL DEFAULT 0,
                INDEX idx_ale_tenant          (tenant_id),
                INDEX idx_ale_category        (category),
                INDEX idx_ale_tenant_category (tenant_id, category),
                INDEX idx_ale_use_count       (use_count),
                INDEX IDX_ale_created_by      (created_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE answer_library_entry
                ADD CONSTRAINT FK_ale_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE answer_library_entry
                ADD CONSTRAINT FK_ale_created_by FOREIGN KEY (created_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop FK constraints before the table (InnoDB requires this ordering).
        $this->addSql('ALTER TABLE answer_library_entry DROP FOREIGN KEY FK_ale_created_by');
        $this->addSql('ALTER TABLE answer_library_entry DROP FOREIGN KEY FK_ale_tenant');
        $this->addSql('DROP TABLE IF EXISTS answer_library_entry');
    }
}
