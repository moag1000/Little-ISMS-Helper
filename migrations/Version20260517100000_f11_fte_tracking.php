<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F11 FTE-Tracking — create fte_tracking_metric + fte_calibration_constant tables.
 *
 * isTransactional() = false:
 *   DDL (CREATE TABLE) on MySQL/MariaDB auto-commits; keeping this transactional
 *   causes SAVEPOINT failures when running multiple migrations in one call.
 *
 * System-default FteCalibrationConstant rows are seeded with tenant_id = NULL
 * so FteCalibrationConstantRepository can fall back to them without a per-tenant
 * row existing. INSERT is a data operation; it is grouped here for convenience
 * and is safe within a non-transactional migration.
 */
final class Version20260517100000_f11_fte_tracking extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F11: create fte_tracking_metric + fte_calibration_constant tables with system-default calibration seed';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // ── FTE Tracking Metric ────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS fte_tracking_metric (
                id                       INT AUTO_INCREMENT NOT NULL,
                tenant_id                INT NOT NULL,
                source                   VARCHAR(64) NOT NULL,
                entity_type              VARCHAR(128) NOT NULL,
                entity_id                INT DEFAULT NULL,
                manual_minutes_estimate  INT NOT NULL,
                actual_minutes_estimate  INT NOT NULL,
                savings_minutes          INT NOT NULL,
                recorded_at              DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                period                   VARCHAR(32) NOT NULL,
                metadata                 JSON DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_fte_tenant_recorded (tenant_id, recorded_at),
                CONSTRAINT fk_fte_metric_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        // ── FTE Calibration Constant ───────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS fte_calibration_constant (
                id                    INT AUTO_INCREMENT NOT NULL,
                tenant_id             INT DEFAULT NULL,
                operation_type        VARCHAR(128) NOT NULL,
                minutes_per_operation DECIMAL(6, 2) NOT NULL,
                last_updated_by_id    INT DEFAULT NULL,
                last_updated_at       DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE KEY uniq_fte_tenant_op (tenant_id, operation_type),
                CONSTRAINT fk_fte_calib_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_fte_calib_user   FOREIGN KEY (last_updated_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        // ── System-default calibration seed (tenant_id = NULL) ────────────────
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO fte_calibration_constant
                (tenant_id, operation_type, minutes_per_operation, last_updated_by_id, last_updated_at)
            VALUES
                (NULL, 'manual_user_provisioning',                  20.00, NULL, NOW()),
                (NULL, 'manual_asset_creation',                      3.00, NULL, NOW()),
                (NULL, 'manual_risk_creation',                       5.00, NULL, NOW()),
                (NULL, 'manual_control_mapping',                     4.00, NULL, NOW()),
                (NULL, 'single_framework_evidence_maintenance',      8.00, NULL, NOW()),
                (NULL, 'manual_business_process_creation',           3.00, NULL, NOW())
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS fte_tracking_metric');
        $this->addSql('DROP TABLE IF EXISTS fte_calibration_constant');
    }
}
