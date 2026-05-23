<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit Cluster D (2026-05-22) — consolidated DDL for two
 * related changes on the CAPA-/Change-Request lineage:
 *
 *   • C4-02 — `CorrectiveAction.relatedControls` (M2M).
 *     ISO 27001 Cl. 10.1 + A.8.15/A.8.16: a logging-related corrective
 *     action typically spans multiple controls. Creates the join table
 *     `corrective_action_controls`. There is no singular predecessor
 *     column on `corrective_actions` to backfill from — the audit
 *     finding's `relatedControls` link already carries the upstream
 *     context.
 *
 *   • C4-05 — `ChangeRequest.relatedFinding` + `relatedCorrectiveAction`
 *     nullable FKs reinstate the lineage chain
 *     "Finding → CAPA → Change → Implementation" without parsing the
 *     free-text justification (ISO 27001 Cl. 10.1).
 *
 * `isTransactional() = false` — MySQL implicitly commits CREATE TABLE /
 * ALTER TABLE which invalidates Doctrine's per-migration SAVEPOINT
 * (CLAUDE.md Common-Pitfalls #6).
 *
 * Per memory `migration-consolidation`: ONE migration per cluster
 * rollout, not one-per-entity.
 */
final class Version20260607100000_capa_multi_control_and_change_request_lineage extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Junior-ISB Cluster-D: corrective_action_controls join table (C4-02) + change_request lineage FKs (C4-05).';
    }

    public function up(Schema $schema): void
    {
        // ── C4-02: corrective_action_controls (M2M) ─────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS corrective_action_controls (
                corrective_action_id INT NOT NULL,
                control_id INT NOT NULL,
                PRIMARY KEY (corrective_action_id, control_id),
                INDEX IDX_corrective_action_controls_ca (corrective_action_id),
                INDEX IDX_corrective_action_controls_control (control_id),
                CONSTRAINT FK_corrective_action_controls_ca
                    FOREIGN KEY (corrective_action_id) REFERENCES corrective_actions (id) ON DELETE CASCADE,
                CONSTRAINT FK_corrective_action_controls_control
                    FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── C4-05: change_request lineage FKs ───────────────────────────
        // Plain `ADD COLUMN` — re-running the migration after a partial
        // failure is rare and the operator-UI's schema-reconcile flow
        // covers that edge. Mirrors the pattern in
        // Version20260524100000_audit_finding_related_controls.
        $this->addSql(<<<'SQL'
            ALTER TABLE change_request
                ADD COLUMN related_finding_id INT DEFAULT NULL,
                ADD COLUMN related_corrective_action_id INT DEFAULT NULL,
                ADD CONSTRAINT FK_change_request_related_finding
                    FOREIGN KEY (related_finding_id) REFERENCES audit_findings (id) ON DELETE SET NULL,
                ADD CONSTRAINT FK_change_request_related_corrective_action
                    FOREIGN KEY (related_corrective_action_id) REFERENCES corrective_actions (id) ON DELETE SET NULL,
                ADD INDEX IDX_change_request_related_finding (related_finding_id),
                ADD INDEX IDX_change_request_related_corrective_action (related_corrective_action_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS corrective_action_controls');

        $this->addSql(<<<'SQL'
            ALTER TABLE change_request
                DROP FOREIGN KEY FK_change_request_related_finding,
                DROP FOREIGN KEY FK_change_request_related_corrective_action,
                DROP INDEX IDX_change_request_related_finding,
                DROP INDEX IDX_change_request_related_corrective_action,
                DROP COLUMN related_finding_id,
                DROP COLUMN related_corrective_action_id
        SQL);
    }
}
