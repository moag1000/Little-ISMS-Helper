<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-7 (DATA_REUSE_IMPROVEMENT_PLAN.md v1.1): Compare-PDF + Scheduled
 * Cross-Framework Reports.
 *
 * Adds a generic `payload` JSON column to `scheduled_report` so new report
 * types (initially `portfolio`) can carry typed configuration (e.g. a list
 * of framework codes) without schema churn.
 */
final class Version20260417232000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-7: scheduled_report.payload JSON + portfolio report type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_report
            ADD payload JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_report DROP payload');
    }
}
