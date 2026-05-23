<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit-2026-05-22 C2-05 — CAPA-Canonical-Process schema additions.
 *
 * Per ADR `docs/decisions/2026-05-23-capa-canonical-process.md` (S14 Phase 1):
 *   1. `corrective_actions.source_type` VARCHAR(30) NOT NULL DEFAULT 'audit_finding'
 *      (enum-ish: audit_finding | incident | manual | change_request)
 *   2. `corrective_actions.source_incident_id` INT NULL + FK + index
 *   3. `corrective_actions.finding_id` → nullable (was NOT NULL)
 *
 * Additive / non-breaking — every existing row keeps `source_type='audit_finding'`
 * and `finding_id` populated, the listener-driven Incident flow lights up only
 * for new high/critical Incidents with rootCause set.
 *
 * `isTransactional() = false` per CLAUDE.md pitfall #6 (multi-DDL).
 */
final class Version20260605100000_corrective_action_incident_source extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'C2-05: CorrectiveAction.sourceType + sourceIncident FK; finding_id nullable.';
    }

    public function up(Schema $schema): void
    {
        // 1) source_type column (default 'audit_finding' covers existing rows).
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            ADD COLUMN IF NOT EXISTS source_type VARCHAR(30) NOT NULL DEFAULT 'audit_finding'
        SQL);

        // 2) source_incident_id nullable FK.
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            ADD COLUMN IF NOT EXISTS source_incident_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            ADD CONSTRAINT FK_corrective_actions_source_incident
            FOREIGN KEY (source_incident_id) REFERENCES incident (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ca_source_incident ON corrective_actions (source_incident_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ca_source_type ON corrective_actions (source_type)
        SQL);

        // 3) finding_id → nullable (was NOT NULL).
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            MODIFY COLUMN finding_id INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Reverse 3) — finding_id back to NOT NULL only if no rows violate it.
        $this->addSql(<<<'SQL'
            ALTER TABLE corrective_actions
            MODIFY COLUMN finding_id INT NOT NULL
        SQL);

        // Reverse 2) — drop FK + index + column.
        $this->addSql('DROP INDEX IF EXISTS idx_ca_source_type ON corrective_actions');
        $this->addSql('DROP INDEX IF EXISTS idx_ca_source_incident ON corrective_actions');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_corrective_actions_source_incident');
        $this->addSql('ALTER TABLE corrective_actions DROP COLUMN source_incident_id');

        // Reverse 1).
        $this->addSql('ALTER TABLE corrective_actions DROP COLUMN source_type');
    }
}
