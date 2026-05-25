<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S17 B4 — Hybrid JSON Nonconformity-Details on `audit_findings`
 * (ISO 27001:2022 Cl. 10.2 b) + d) — Root-Cause-Analysis, CAPA, Verification).
 *
 * Additive schema:
 *   - nonconformity_details  JSON       (root-cause method, CAPA list, verification)
 *   - nc_root_cause_summary  TEXT       (short narrative)
 *   - nc_correction_due_date DATE       (auditable deadline — Cl. 10.2 c))
 *   - nc_verified_at         DATETIME   (effectiveness-verification timestamp)
 *   - nc_verified_by_id      INT FK→users(id) ON DELETE SET NULL
 *   - idx_audit_finding_nc_due       (nc_correction_due_date)
 *   - idx_audit_finding_nc_verified  (nc_verified_at)
 *
 * `isTransactional() = false` per CLAUDE.md pitfall #6 (multi-DDL ALTER TABLE).
 */
final class Version20260614100000_AuditFindingNonconformityHybridJson extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S17 B4: Hybrid JSON Nonconformity details on audit_findings (ISO 27001 Cl. 10.2).';
    }

    public function up(Schema $schema): void
    {
        // 1) JSON details column.
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_findings
            ADD COLUMN IF NOT EXISTS nonconformity_details JSON DEFAULT NULL
        SQL);

        // 2) Root-cause summary (free-text).
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_findings
            ADD COLUMN IF NOT EXISTS nc_root_cause_summary TEXT DEFAULT NULL
        SQL);

        // 3) Correction due date (indexed for overdue queries).
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_findings
            ADD COLUMN IF NOT EXISTS nc_correction_due_date DATE DEFAULT NULL
        SQL);

        // 4) Verification timestamp (indexed for verification-rate KPIs).
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_findings
            ADD COLUMN IF NOT EXISTS nc_verified_at DATETIME DEFAULT NULL
        SQL);

        // 5) Verifier FK.
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_findings
            ADD COLUMN IF NOT EXISTS nc_verified_by_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE audit_findings
            ADD CONSTRAINT FK_audit_findings_nc_verified_by
            FOREIGN KEY (nc_verified_by_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);

        // 6) Indexes.
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_audit_finding_nc_due ON audit_findings (nc_correction_due_date)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_audit_finding_nc_verified ON audit_findings (nc_verified_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_audit_finding_nc_verified ON audit_findings');
        $this->addSql('DROP INDEX IF EXISTS idx_audit_finding_nc_due ON audit_findings');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY FK_audit_findings_nc_verified_by');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN nc_verified_by_id');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN nc_verified_at');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN nc_correction_due_date');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN nc_root_cause_summary');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN nonconformity_details');
    }
}
