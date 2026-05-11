<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 1 — F2 Wave 1 Task F2.1: BulkImportBatch + BulkImportRow tables.
 *
 * Anchors the hybrid audit-trail for bulk-import operations (ISO 27001
 * Clause 7.5.3). Batch-id correlates per-row entries to one batch entry
 * via AuditLogger::logBulk() (CC3 Sprint 0).
 *
 * DDL only — non-transactional per `feedback_migration_savepoint` memory.
 */
final class Version20260511101408 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sprint 1 F2.1 — BulkImportBatch + BulkImportRow tables for bulk-import audit-trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE bulk_import_batch (
              id INT AUTO_INCREMENT NOT NULL,
              entity_type VARCHAR(64) NOT NULL,
              batch_id VARCHAR(36) DEFAULT NULL,
              mode VARCHAR(16) NOT NULL,
              status VARCHAR(32) NOT NULL,
              source_file_name VARCHAR(255) NOT NULL,
              source_file_hash VARCHAR(64) NOT NULL,
              source_file_size BIGINT NOT NULL,
              dry_run_result_hash VARCHAR(64) DEFAULT NULL,
              column_mapping JSON DEFAULT NULL,
              row_count_total INT NOT NULL,
              row_count_success INT NOT NULL,
              row_count_skipped INT NOT NULL,
              row_count_error INT NOT NULL,
              row_count_updated INT NOT NULL,
              notes LONGTEXT DEFAULT NULL,
              created_at DATETIME NOT NULL,
              committed_at DATETIME DEFAULT NULL,
              tenant_id INT NOT NULL,
              source_document_id INT DEFAULT NULL,
              executed_by_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_4F56D989F39EBE7A (batch_id),
              INDEX IDX_4F56D9899033212A (tenant_id),
              INDEX IDX_4F56D989FF402897 (source_document_id),
              INDEX IDX_4F56D9898B35AB5C (executed_by_id),
              INDEX idx_bulk_import_batch_tenant_created (tenant_id, created_at),
              INDEX idx_bulk_import_batch_entity_type (entity_type),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE bulk_import_row (
              id INT AUTO_INCREMENT NOT NULL,
              `row_number` INT NOT NULL,
              status VARCHAR(16) NOT NULL,
              action VARCHAR(16) DEFAULT NULL,
              entity_id INT DEFAULT NULL,
              parsed_data JSON NOT NULL,
              old_values JSON DEFAULT NULL,
              new_values JSON DEFAULT NULL,
              error_message LONGTEXT DEFAULT NULL,
              batch_id INT NOT NULL,
              INDEX IDX_86BF96D1F39EBE7A (batch_id),
              INDEX idx_bulk_import_row_batch_status (batch_id, status),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE bulk_import_batch ADD CONSTRAINT FK_4F56D9899033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE bulk_import_batch ADD CONSTRAINT FK_4F56D989FF402897 FOREIGN KEY (source_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bulk_import_batch ADD CONSTRAINT FK_4F56D9898B35AB5C FOREIGN KEY (executed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bulk_import_row ADD CONSTRAINT FK_86BF96D1F39EBE7A FOREIGN KEY (batch_id) REFERENCES bulk_import_batch (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bulk_import_batch DROP FOREIGN KEY FK_4F56D9899033212A');
        $this->addSql('ALTER TABLE bulk_import_batch DROP FOREIGN KEY FK_4F56D989FF402897');
        $this->addSql('ALTER TABLE bulk_import_batch DROP FOREIGN KEY FK_4F56D9898B35AB5C');
        $this->addSql('ALTER TABLE bulk_import_row DROP FOREIGN KEY FK_86BF96D1F39EBE7A');
        $this->addSql('DROP TABLE bulk_import_batch');
        $this->addSql('DROP TABLE bulk_import_row');
    }
}
