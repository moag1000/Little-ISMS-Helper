<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bucket-6 (RT_06) — create dora_exit_plan table.
 *
 * Captures Art. 28(8) exit-strategy artefacts per critical Supplier:
 *  data-return mechanism, deletion confirmation, migration path,
 *  rehearsal date, estimated duration + cost.
 *
 * One row per Supplier — enforced by UNIQUE(supplier_id). FK CASCADE
 * removes the plan when the Supplier is deleted; deletion-certificate
 * Document FK is SET NULL so the plan survives doc retention sweeps.
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 — MySQL CREATE TABLE
 * commits implicitly and would invalidate the Doctrine SAVEPOINT when
 * batched with other DDL migrations.
 */
final class Version20260617100000_DoraExitPlan extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Bucket-6 RT_06: dora_exit_plan table (one exit plan per DORA-critical Supplier).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE dora_exit_plan ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'tenant_id INT DEFAULT NULL, '
            . 'supplier_id INT NOT NULL, '
            . 'deletion_certificate_doc_id INT DEFAULT NULL, '
            . 'exit_trigger VARCHAR(30) NOT NULL, '
            . 'data_return_format LONGTEXT DEFAULT NULL, '
            . 'data_deletion_confirmation TINYINT(1) NOT NULL, '
            . 'migration_path LONGTEXT DEFAULT NULL, '
            . 'tested_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'estimated_duration_days INT DEFAULT NULL, '
            . 'estimated_cost NUMERIC(15, 2) DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'UNIQUE INDEX uniq_dora_exit_plan_supplier (supplier_id), '
            . 'INDEX idx_dora_exit_plan_tenant (tenant_id), '
            . 'INDEX idx_dora_exit_plan_tested_at (tested_at), '
            . 'INDEX IDX_DORA_EXIT_PLAN_DOC (deletion_certificate_doc_id), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE dora_exit_plan '
            . 'ADD CONSTRAINT FK_DORA_EXIT_PLAN_TENANT FOREIGN KEY (tenant_id) '
            . 'REFERENCES tenant (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE dora_exit_plan '
            . 'ADD CONSTRAINT FK_DORA_EXIT_PLAN_SUPPLIER FOREIGN KEY (supplier_id) '
            . 'REFERENCES supplier (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE dora_exit_plan '
            . 'ADD CONSTRAINT FK_DORA_EXIT_PLAN_DOC FOREIGN KEY (deletion_certificate_doc_id) '
            . 'REFERENCES document (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS dora_exit_plan');
    }
}
