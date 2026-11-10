<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S32: a failing scheduled report is retried with back-off (1, 2, 4, … hours, at most 24) instead of every hourly run.
 * The streak of failed runs lives on the report.
 */
final class Version20261109200000_ScheduledReportFailedRuns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduled report: failed_runs counter for the retry back-off';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('scheduled_report')->hasColumn('failed_runs')) {
            return;
        }
        $this->addSql('ALTER TABLE scheduled_report ADD failed_runs INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_report DROP failed_runs');
    }
}
