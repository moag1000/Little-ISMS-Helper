<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F30 — DORA Register of Information (RoI) table.
 *
 * Creates dora_register_of_information to track XBRL export and submission
 * events for each tenant's DORA Art. 28 RoI obligation.
 *
 * DORA: Art. 28 — Register of information on ICT third-party service providers
 * ESA Joint RTS 2024/xxx — XBRL taxonomy for RoI submissions
 *
 * isTransactional() = false — DDL (CREATE TABLE) commits implicitly in MySQL,
 * which invalidates Doctrine's SAVEPOINT-per-migration. This avoids
 * "SAVEPOINT DOCTRINE_X does not exist" errors in multi-migration runs.
 */
final class Version20260516100000_f30_dora_register_of_information extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F30: Create dora_register_of_information table for DORA Art. 28 RoI tracking';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS dora_register_of_information (
                id                 INT NOT NULL AUTO_INCREMENT,
                tenant_id          INT NOT NULL,
                submitted_by_id    INT DEFAULT NULL,
                reporting_date     DATE NOT NULL,
                reporting_scope    VARCHAR(30) NOT NULL DEFAULT 'yearly_full',
                submitted_at       DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                payload_hash       VARCHAR(64) DEFAULT NULL,
                confirmation_number VARCHAR(100) DEFAULT NULL,
                created_at         DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE KEY uniq_tenant_date (tenant_id, reporting_date),
                KEY idx_dora_roi_tenant (tenant_id),
                KEY idx_dora_roi_reporting_date (reporting_date),
                CONSTRAINT fk_dora_roi_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_dora_roi_submitted_by
                    FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS dora_register_of_information');
    }
}
