<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NIS2 Article 23 Incident Reporting Timeline Fields
 *
 * Adds fields for tracking NIS2 Directive (EU 2022/2555) Article 23 compliance:
 * - Early warning notification (24h deadline)
 * - Detailed notification (72h deadline)
 * - Final report (1 month deadline)
 * - Additional impact assessment fields
 *
 * Phase 6H: NIS2 Incident Response Module
 */
final class Version20251110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add NIS2 Article 23 incident reporting timeline fields';
    }

    public function up(Schema $schema): void
    {
        // NIS2 Article 23 - Reporting Timeline Timestamps
        $this->addSql('ALTER TABLE incident ADD early_warning_reported_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE incident ADD detailed_notification_reported_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE incident ADD final_report_submitted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // NIS2 Categorization
        $this->addSql('ALTER TABLE incident ADD nis2_category VARCHAR(50) DEFAULT NULL');

        // Cross-border Impact (required field, defaults to false)
        $this->addSql('ALTER TABLE incident ADD cross_border_impact TINYINT(1) NOT NULL DEFAULT 0');

        // Impact Assessment Fields
        $this->addSql('ALTER TABLE incident ADD affected_users_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE incident ADD estimated_financial_impact NUMERIC(12, 2) DEFAULT NULL');

        // Authority Notification Fields
        $this->addSql('ALTER TABLE incident ADD national_authority_notified VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident ADD authority_reference_number VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Rollback NIS2 fields
        $this->addSql('ALTER TABLE incident DROP early_warning_reported_at');
        $this->addSql('ALTER TABLE incident DROP detailed_notification_reported_at');
        $this->addSql('ALTER TABLE incident DROP final_report_submitted_at');
        $this->addSql('ALTER TABLE incident DROP nis2_category');
        $this->addSql('ALTER TABLE incident DROP cross_border_impact');
        $this->addSql('ALTER TABLE incident DROP affected_users_count');
        $this->addSql('ALTER TABLE incident DROP estimated_financial_impact');
        $this->addSql('ALTER TABLE incident DROP national_authority_notified');
        $this->addSql('ALTER TABLE incident DROP authority_reference_number');
    }
}
