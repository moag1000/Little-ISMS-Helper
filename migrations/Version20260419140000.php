<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * kpi_snapshot table — daily KPI snapshots per tenant for trend tracking.
 */
final class Version20260419140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'kpi_snapshot: daily KPI snapshots per tenant for trend analytics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS kpi_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            snapshot_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            kpi_data JSON NOT NULL COMMENT \'(DC2Type:json)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_kpi_snapshot_tenant_date (tenant_id, snapshot_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='kpi_snapshot' AND CONSTRAINT_NAME='FK_KPI_SNAPSHOT_TENANT')");
        $this->addSql("SET @sql := IF(@fk_exists = 0, 'ALTER TABLE kpi_snapshot ADD CONSTRAINT FK_KPI_SNAPSHOT_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kpi_snapshot DROP FOREIGN KEY FK_KPI_SNAPSHOT_TENANT');
        $this->addSql('DROP TABLE kpi_snapshot');
    }
}
