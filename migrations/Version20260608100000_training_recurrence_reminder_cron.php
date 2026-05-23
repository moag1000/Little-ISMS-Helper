<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Junior-ISB-Audit Cluster C — Awareness-Workflow (S14, 2026-05-23):
 * Training recurrence + reminder timestamp.
 *
 *   • C3-02 — `training.recurrence_months` (INT, nullable):
 *     ISO 27001 A.6.3 cadence in months. NULL = one-off training.
 *
 *   • C3-02 — `training.last_reminder_sent_at` (DATETIME, nullable):
 *     Bookkeeping timestamp consumed by the
 *     `app:training-send-reminders` cron command.
 *
 * `isTransactional() = false` — MySQL implicitly commits ALTER TABLE
 * which invalidates Doctrine's per-migration SAVEPOINT (CLAUDE.md
 * Common-Pitfalls #6).
 *
 * Per memory `migration-consolidation`: ONE migration per cluster
 * rollout, not one per entity field. Cluster-C touches only Training;
 * the C3-03 / C3-04 Document changes are FormType-only with no DDL.
 */
final class Version20260608100000_training_recurrence_reminder_cron extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Junior-ISB Cluster-C: training.recurrence_months + training.last_reminder_sent_at (C3-02).';
    }

    public function up(Schema $schema): void
    {
        // Idempotent ADD COLUMN — MariaDB / MySQL >= 8.0.13 supports the
        // `ADD COLUMN IF NOT EXISTS` form natively. Avoids PREPARE/EXECUTE
        // pattern (CLAUDE.md Common-Pitfalls #6).
        $this->addSql(<<<'SQL'
            ALTER TABLE training
                ADD COLUMN IF NOT EXISTS recurrence_months INT DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS last_reminder_sent_at DATETIME DEFAULT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE training
                DROP COLUMN IF EXISTS recurrence_months,
                DROP COLUMN IF EXISTS last_reminder_sent_at
        SQL);
    }
}
