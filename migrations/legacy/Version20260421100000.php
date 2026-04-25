<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 4 / R3: Reuse-Trend-Snapshot für 12-Monats-Chart.
 */
final class Version20260421100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'R3: reuse_trend_snapshot (tenant + captured_day unique, daily granularity)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reuse_trend_snapshot (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                captured_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                captured_day DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                fte_saved_total DOUBLE PRECISION NOT NULL DEFAULT 0,
                inherited_count INT NOT NULL DEFAULT 0,
                fulfillments_total INT NOT NULL DEFAULT 0,
                inheritance_rate_pct INT NOT NULL DEFAULT 0,
                INDEX idx_rts_tenant_date (tenant_id, captured_at),
                UNIQUE INDEX uniq_rts_tenant_day (tenant_id, captured_day),
                CONSTRAINT fk_rts_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS reuse_trend_snapshot');
    }
}
