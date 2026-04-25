<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CM-3: portfolio_snapshot table for real trend deltas on the cross-framework
 * portfolio report. One row per (tenant, day, framework, NIST CSF category).
 */
final class Version20260419210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CM-3: portfolio_snapshot table (trend data for PortfolioReportService)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portfolio_snapshot')");
        $this->addSql("SET @sql := IF(@tbl = 0, '
            CREATE TABLE portfolio_snapshot (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                snapshot_date DATE NOT NULL COMMENT \"(DC2Type:date_immutable)\",
                framework_code VARCHAR(50) NOT NULL,
                nist_csf_category VARCHAR(20) NOT NULL,
                fulfillment_percentage INT NOT NULL,
                requirement_count INT NOT NULL,
                gap_count INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\",
                INDEX idx_portfolio_snapshot_tenant_date (tenant_id, snapshot_date),
                INDEX idx_portfolio_snapshot_framework (tenant_id, framework_code),
                UNIQUE INDEX uniq_portfolio_snapshot_day (tenant_id, snapshot_date, framework_code, nist_csf_category),
                PRIMARY KEY(id),
                CONSTRAINT FK_portfolio_snapshot_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS portfolio_snapshot');
    }
}
