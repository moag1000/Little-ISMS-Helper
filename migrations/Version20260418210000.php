<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ISB MINOR-4 (docs/DATA_REUSE_PLAN_REVIEW_ISB.md) — evidence column for
 * the Symfony mailer TLS pre-flight. Populated by ScheduledReportService
 * right after `MailerTlsChecker::assertTlsConfigured()` succeeds so the
 * auditor can trace "TLS was verified at <timestamp> before this run".
 */
final class Version20260418210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_report.tls_verified_at — evidence column for mailer TLS pre-flight (DSGVO Art. 32, ISO 27001 A.5.34).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_report ADD COLUMN tls_verified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_report DROP COLUMN tls_verified_at');
    }
}
