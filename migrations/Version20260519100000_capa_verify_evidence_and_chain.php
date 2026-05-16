<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S3 P0-30/31/32 — CAPA verification evidence + follow-up chaining.
 *
 * Adds four columns to `corrective_actions` to enforce the ISO 27001
 * Cl. 10.1 (b) effectiveness loop:
 *
 *  - verified_by_id          FK → users (id) ON DELETE SET NULL
 *                            User who confirmed/refuted effectiveness.
 *  - verified_at             DATETIME nullable
 *                            Timestamp of the verification.
 *  - effectiveness_evidence  TEXT nullable
 *                            Pflicht-Beleg (audit-document, re-test report,
 *                            Re-Audit-Befund) — guarded server-side.
 *  - previous_capa_id        FK → corrective_actions (id) ON DELETE SET NULL
 *                            Self-FK linking a follow-up CAPA back to its
 *                            unwirksame Vorgänger-CAPA.
 *
 * Data-Backfill: existing rows with status `verified_effective` or
 * `verified_ineffective` and NULL verified_at get verified_at = created_at
 * as a best-effort baseline. This keeps the audit trail non-empty for
 * historical records without inventing a Verifier-User.
 *
 * isTransactional()=false because each ALTER TABLE in MySQL commits
 * implicitly — otherwise the next DDL migration in the same run trips
 * Doctrine's SAVEPOINT guard.
 */
final class Version20260519100000_capa_verify_evidence_and_chain extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S3 P0-30/31/32: add verified_by/at, effectiveness_evidence, previous_capa_id to corrective_actions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
                ADD COLUMN verified_by_id INT DEFAULT NULL,
                ADD COLUMN verified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD COLUMN effectiveness_evidence LONGTEXT DEFAULT NULL,
                ADD COLUMN previous_capa_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
                ADD CONSTRAINT FK_ca_verified_by
                    FOREIGN KEY (verified_by_id) REFERENCES users (id) ON DELETE SET NULL,
                ADD CONSTRAINT FK_ca_previous_capa
                    FOREIGN KEY (previous_capa_id) REFERENCES corrective_actions (id) ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_ca_verified_by ON corrective_actions (verified_by_id)');
        $this->addSql('CREATE INDEX IDX_ca_previous_capa ON corrective_actions (previous_capa_id)');

        // Best-effort backfill: historical verifications get a synthetic
        // verified_at = created_at so the audit trail isn't NULL where the
        // CAPA is already in a verified_* terminal state.
        $this->addSql(<<<'SQL'
            UPDATE corrective_actions
            SET verified_at = created_at
            WHERE status IN ('verified_effective', 'verified_ineffective')
              AND verified_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_ca_verified_by');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_ca_previous_capa');
        $this->addSql('DROP INDEX IDX_ca_verified_by ON corrective_actions');
        $this->addSql('DROP INDEX IDX_ca_previous_capa ON corrective_actions');
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
                DROP COLUMN verified_by_id,
                DROP COLUMN verified_at,
                DROP COLUMN effectiveness_evidence,
                DROP COLUMN previous_capa_id
        SQL);
    }
}
