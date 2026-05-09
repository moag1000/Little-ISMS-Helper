<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W3-C — DocumentSection sub-workflow.
 *
 * Backs the `App\Entity\DocumentSection` entity that implements the
 * per-section DPO veto state machine described in
 * `docs/plans/policy-wizard/06-dpo-input.md` §0.A. Each row is a single
 * privacy section (or any other gated section) inside a host
 * `Document`, with its own approval lifecycle independent of the host
 * document. Closes self-review item-4 of the Phase 4-C DPO addon.
 *
 * Plain SQL only (no PREPARE/EXECUTE — see CLAUDE.md pitfall #6).
 * `isTransactional()` returns false because MySQL ALTER/CREATE TABLE
 * statements implicitly commit (see CLAUDE.md pitfall #6).
 */
final class Version20260508131000_policy_wizard_w3_document_section extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W3-C: add document_section table for DPO per-section veto sub-workflow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_section (
                id INT AUTO_INCREMENT NOT NULL,
                document_id INT NOT NULL,
                tenant_id INT NOT NULL,
                section_key VARCHAR(100) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
                content_snapshot LONGTEXT DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                approved_by_user_id INT DEFAULT NULL,
                rejected_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                rejected_by_user_id INT DEFAULT NULL,
                rejection_reason LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uq_document_section_doc_key (document_id, section_key),
                INDEX idx_document_section_doc_status (document_id, status),
                INDEX idx_document_section_tenant (tenant_id),
                INDEX idx_document_section_approved_by (approved_by_user_id),
                INDEX idx_document_section_rejected_by (rejected_by_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document_section
                ADD CONSTRAINT FK_document_section_document
                FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document_section
                ADD CONSTRAINT FK_document_section_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document_section
                ADD CONSTRAINT FK_document_section_approved_by_user
                FOREIGN KEY (approved_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document_section
                ADD CONSTRAINT FK_document_section_rejected_by_user
                FOREIGN KEY (rejected_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_document_section_rejected_by_user');
        $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_document_section_approved_by_user');
        $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_document_section_tenant');
        $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_document_section_document');
        $this->addSql('DROP TABLE IF EXISTS document_section');
    }
}
