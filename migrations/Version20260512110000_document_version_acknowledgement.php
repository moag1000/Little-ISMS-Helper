<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit V3 W2-Bug2 — extend `document` with `version` + `requires_acknowledgement`.
 *
 * Both columns are pre-conditions for the
 * {@see \App\EventListener\AutoReactionAcknowledgementCampaignListener}:
 * without them the listener short-circuits silently and the
 * ISO 27001 A.6.3 ("policy must be communicated and acknowledged")
 * fan-out never fires.
 *
 *  - `version`: VARCHAR(32) NOT NULL DEFAULT '1.0' — string-typed so
 *    semantic versioning ("1.2.3-beta") and date-stamped policies
 *    ("2026-Q2") both fit.
 *  - `requires_acknowledgement`: TINYINT(1) NOT NULL DEFAULT 0 — opt-in
 *    per document so uploaded evidence files do not silently spawn
 *    acknowledgement campaigns.
 *
 * Idempotent: every step checks information_schema first so partial
 * re-runs never fail. Plain `ALTER TABLE` only — no PREPARE/EXECUTE
 * (CLAUDE.md pitfall #6).
 *
 * `isTransactional() = false`: every ALTER TABLE implicitly commits in
 * MySQL; running >1 DDL migration in a single `migrate` call without
 * this override fails on the SAVEPOINT (CLAUDE.md pitfall #6).
 */
final class Version20260512110000_document_version_acknowledgement extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit V3 W2-Bug2: add document.version + document.requires_acknowledgement '
            . '(pre-conditions for Auto-Acknowledgement-Campaign listener).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('document', 'version')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN version VARCHAR(32) NOT NULL DEFAULT '1.0'
            SQL);
        }
        if (!$this->columnExists('document', 'requires_acknowledgement')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN requires_acknowledgement TINYINT(1) NOT NULL DEFAULT 0
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('document', 'requires_acknowledgement')) {
            $this->addSql('ALTER TABLE document DROP COLUMN requires_acknowledgement');
        }
        if ($this->columnExists('document', 'version')) {
            $this->addSql('ALTER TABLE document DROP COLUMN version');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c',
            ['t' => $table, 'c' => $column],
        );
        return ((int) $row) > 0;
    }
}
