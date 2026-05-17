<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * junior-isb-audit P0-NEW (2026-05-17) — AuditFinding.relatedControl was
 * singular but ISO-27001-findings often touch multiple controls
 * (e.g. a logging-related finding hits A.8.15 + A.8.16). Adds a
 * `audit_finding_controls` join table and backfills the existing
 * singular FK rows.
 *
 * `isTransactional() = false` — MySQL implicitly commits CREATE TABLE /
 * INSERT which invalidates Doctrine's per-migration SAVEPOINT
 * (CLAUDE.md Common-Pitfalls #6).
 */
final class Version20260524100000_audit_finding_related_controls extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Junior-ISB P0-NEW: audit_finding_controls join + backfill from singular relatedControl.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_finding_controls (
                audit_finding_id INT NOT NULL,
                control_id INT NOT NULL,
                PRIMARY KEY (audit_finding_id, control_id),
                INDEX IDX_audit_finding_controls_finding (audit_finding_id),
                INDEX IDX_audit_finding_controls_control (control_id),
                CONSTRAINT FK_audit_finding_controls_finding
                    FOREIGN KEY (audit_finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE,
                CONSTRAINT FK_audit_finding_controls_control
                    FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // Backfill: every row with a non-NULL singular FK becomes a join-row.
        // INSERT IGNORE handles re-runs / partial state.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO audit_finding_controls (audit_finding_id, control_id)
            SELECT id, related_control_id
            FROM audit_findings
            WHERE related_control_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_finding_controls');
    }
}
