<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S2 / CM-6: Industry-Baselines (starter packs) + application audit trail.
 *
 * industry_baseline holds globally-available starter packs per industry
 * (production, finance, kritis_health, automotive, cloud, public_sector,
 * generic). Each pack lists required/recommended frameworks, preset
 * risks/assets/controls, and an FTE-savings estimate.
 *
 * applied_baseline records which baseline was applied to which tenant,
 * when, by whom — so auditors can trace back "why does this tenant have
 * these 12 preset risks".
 */
final class Version20260419240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S2: industry_baseline + applied_baseline tables (industry starter packs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='industry_baseline')");
        $this->addSql("SET @sql := IF(@tbl = 0, '
            CREATE TABLE industry_baseline (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                industry VARCHAR(30) NOT NULL,
                source VARCHAR(30) NOT NULL,
                required_frameworks JSON NOT NULL,
                recommended_frameworks JSON NOT NULL,
                preset_risks JSON NOT NULL,
                preset_assets JSON NOT NULL,
                preset_applicable_controls JSON NOT NULL,
                fte_days_saved_estimate DOUBLE PRECISION NOT NULL,
                created_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\",
                version VARCHAR(20) NOT NULL,
                UNIQUE INDEX uniq_industry_baseline_code (code),
                INDEX idx_industry_baseline_industry (industry),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applied_baseline')");
        $this->addSql("SET @sql := IF(@tbl = 0, '
            CREATE TABLE applied_baseline (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                applied_by_id INT DEFAULT NULL,
                baseline_code VARCHAR(50) NOT NULL,
                baseline_version VARCHAR(20) NOT NULL,
                applied_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\",
                created_summary JSON NOT NULL,
                UNIQUE INDEX uniq_applied_baseline_tenant_code (tenant_id, baseline_code),
                INDEX idx_applied_baseline_tenant (tenant_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_applied_baseline_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_applied_baseline_user FOREIGN KEY (applied_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS applied_baseline');
        $this->addSql('DROP TABLE IF EXISTS industry_baseline');
    }
}
