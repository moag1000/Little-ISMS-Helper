<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * KPI_IMPROVEMENT_PLAN Phase 4: per-tenant KPI status thresholds.
 */
final class Version20260419100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'kpi_threshold_config: per-tenant overrides for KPI good/warning cut-offs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS kpi_threshold_config (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            kpi_key VARCHAR(100) NOT NULL,
            good_threshold INT NOT NULL,
            warning_threshold INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_kpi_threshold_tenant_key (tenant_id, kpi_key),
            INDEX idx_kpi_threshold_tenant (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='kpi_threshold_config' AND CONSTRAINT_NAME='FK_KPI_THRESHOLD_TENANT')");
        $this->addSql("SET @sql := IF(@fk_exists = 0, 'ALTER TABLE kpi_threshold_config ADD CONSTRAINT FK_KPI_THRESHOLD_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kpi_threshold_config DROP FOREIGN KEY FK_KPI_THRESHOLD_TENANT');
        $this->addSql('DROP TABLE kpi_threshold_config');
    }
}
