<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CM-8: audit_freeze table for tamper-evident Stichtag compliance snapshots.
 * Captures SoA, requirement fulfillments, risks and KPIs at a chosen date,
 * sealed with a SHA-256 hash over the canonical JSON payload. Immutable by
 * design — no update/delete endpoint.
 */
final class Version20260419230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CM-8: audit_freeze table (tamper-evident audit Stichtag snapshots)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_freeze')");
        $this->addSql("SET @sql := IF(@tbl = 0, '
            CREATE TABLE audit_freeze (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                created_by_id INT NOT NULL,
                freeze_name VARCHAR(200) NOT NULL,
                stichtag DATE NOT NULL COMMENT \"(DC2Type:date_immutable)\",
                created_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\",
                framework_codes JSON NOT NULL,
                purpose VARCHAR(50) NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                payload_json JSON NOT NULL,
                payload_sha256 VARCHAR(64) NOT NULL,
                pdf_generated_at DATETIME DEFAULT NULL COMMENT \"(DC2Type:datetime_immutable)\",
                pdf_path VARCHAR(255) DEFAULT NULL,
                INDEX idx_audit_freeze_tenant (tenant_id),
                INDEX idx_audit_freeze_stichtag (tenant_id, stichtag),
                UNIQUE INDEX uniq_audit_freeze_tenant_date_name (tenant_id, stichtag, freeze_name),
                PRIMARY KEY(id),
                CONSTRAINT FK_audit_freeze_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT FK_audit_freeze_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_freeze');
    }
}
