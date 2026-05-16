<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S3 P0-26 — Audit-Bericht 4-Augen-Approval-Workflow (ISO 27001 Cl. 9.2.2 d).
 *
 * Adds the seven approval-workflow columns to `internal_audit`:
 *
 * - reported_by_id / reported_at — auditor who submitted the report
 * - approved_by_id / approved_at — manager who approved (server enforces
 *   approved_by_id != reported_by_id for 4-eyes principle)
 * - rejection_reason             — required when status moves to "rejected"
 * - closed_by_id / closed_at     — who archived the audit cycle
 *
 * All FKs reference `users (id)` (plural — note the schema convention; an
 * earlier PR #388 hit this with the wrong singular `user` table name).
 * ON DELETE SET NULL so users may be removed without breaking the audit
 * trail — the audit record stays, the FK simply nulls out.
 *
 * `isTransactional() = false` because MySQL implicitly commits each
 * ALTER TABLE which invalidates Doctrine's per-migration SAVEPOINT. Any
 * second DDL migration in the same migrate run would fail with
 * "SAVEPOINT DOCTRINE_X does not exist" without the override.
 */
final class Version20260519100000_audit_approval_workflow extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'P0-26: InternalAudit approval workflow columns (reported_by, approved_by, closed_by, rejection_reason + timestamps).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD COLUMN reported_by_id INT DEFAULT NULL,
                ADD COLUMN reported_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD COLUMN approved_by_id INT DEFAULT NULL,
                ADD COLUMN approved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD COLUMN rejection_reason LONGTEXT DEFAULT NULL,
                ADD COLUMN closed_by_id INT DEFAULT NULL,
                ADD COLUMN closed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD CONSTRAINT FK_internal_audit_reported_by
                    FOREIGN KEY (reported_by_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD CONSTRAINT FK_internal_audit_approved_by
                    FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                ADD CONSTRAINT FK_internal_audit_closed_by
                    FOREIGN KEY (closed_by_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_internal_audit_reported_by ON internal_audit (reported_by_id)');
        $this->addSql('CREATE INDEX IDX_internal_audit_approved_by ON internal_audit (approved_by_id)');
        $this->addSql('CREATE INDEX IDX_internal_audit_closed_by ON internal_audit (closed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY FK_internal_audit_reported_by');
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY FK_internal_audit_approved_by');
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY FK_internal_audit_closed_by');

        $this->addSql('DROP INDEX IDX_internal_audit_reported_by ON internal_audit');
        $this->addSql('DROP INDEX IDX_internal_audit_approved_by ON internal_audit');
        $this->addSql('DROP INDEX IDX_internal_audit_closed_by ON internal_audit');

        $this->addSql(<<<'SQL'
            ALTER TABLE internal_audit
                DROP COLUMN reported_by_id,
                DROP COLUMN reported_at,
                DROP COLUMN approved_by_id,
                DROP COLUMN approved_at,
                DROP COLUMN rejection_reason,
                DROP COLUMN closed_by_id,
                DROP COLUMN closed_at
        SQL);
    }
}
