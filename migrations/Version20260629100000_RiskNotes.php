<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `notes` free-text column to `risk`.
 *
 * Backs Risk::$notes — used by IncidentRiskFeedbackService to append an
 * incident-triggered re-evaluation log to the risk. DDL migration —
 * isTransactional() = false (MySQL/MariaDB implicit commit on ALTER).
 */
final class Version20260629100000_RiskNotes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add risk.notes (free-text) column';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk ADD COLUMN IF NOT EXISTS notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk DROP COLUMN IF EXISTS notes');
    }
}
