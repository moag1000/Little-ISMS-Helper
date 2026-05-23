<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit-2026-05-22 M-07 Phase-1 — CAPA-Welt consolidation.
 *
 * Per ADR `docs/decisions/2026-05-23-capa-canonical-process.md`:
 *
 *   Adds `corrective_actions.source_change_request_id` (nullable FK to
 *   `change_request`). Inverse direction of
 *   `change_request.related_corrective_action_id` (added in
 *   Version20260607100000): captures the case where a routine ChangeRequest
 *   *surfaced* a nonconformity that became a CAPA, while
 *   `related_corrective_action_id` captures CRs *opened to execute* a CAPA.
 *
 *   Phase-1 completion: `source_type` (Version20260605100000) and
 *   `source_incident_id` (Version20260605100000) are already in place.
 *   ChangeRequest.related_corrective_action_id is in place
 *   (Version20260607100000). This migration closes the bidirectional
 *   traceability gap.
 *
 * Additive / non-breaking. `isTransactional()=false` per CLAUDE.md pitfall #6
 * (single ALTER TABLE with multiple operations would still implicitly commit
 * on MySQL and invalidate the per-migration SAVEPOINT).
 *
 * Per memory `[[migration-consolidation]]`: ONE migration for this Phase-1
 * Foundation increment.
 */
final class Version20260610100000_capa_source_change_request_fk extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'M-07 Phase-1: CorrectiveAction.source_change_request_id FK + index (ADR 2026-05-23).';
    }

    public function up(Schema $schema): void
    {
        // 1) source_change_request_id nullable FK.
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            ADD COLUMN IF NOT EXISTS source_change_request_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            ADD CONSTRAINT FK_corrective_actions_source_change_request
            FOREIGN KEY (source_change_request_id) REFERENCES change_request (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ca_source_change_request
            ON corrective_actions (source_change_request_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_ca_source_change_request ON corrective_actions');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_corrective_actions_source_change_request');
        $this->addSql('ALTER TABLE corrective_actions DROP COLUMN source_change_request_id');
    }
}
