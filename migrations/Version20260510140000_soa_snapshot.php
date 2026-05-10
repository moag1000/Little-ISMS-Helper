<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Task #128 — SoA point-in-time snapshot table.
 *
 * Closes the persona-walkthrough gap (ISB + Auditor-External): the
 * certification-bundle exporter could only emit the live SoA state.
 * Auditors need a frozen "as-of <cut-off>" view so the export
 * reproduces what was true at the audit date — even when controls,
 * evidence documents or approver identities change after that date.
 *
 * Design decisions:
 *   - `payload` stored as JSON so the schema is forward-compatible
 *     when new SoA dimensions (mappings, BCM-coupling, DPIA refs)
 *     are added without a follow-up migration.
 *   - `checksum_sha256` columns the deterministic hash so the
 *     bundle exporter can prove integrity end-to-end.
 *   - `(tenant_id, as_of_date)` index for the cert-bundle
 *     `findByTenantAndDate()` lookup hot-path.
 *
 * `isTransactional() = false` — DDL implicitly commits in MySQL
 * (CLAUDE.md pitfall #6). Required for any migration that touches
 * `CREATE TABLE` in a multi-migration `migrate` run.
 */
final class Version20260510140000_soa_snapshot extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Task #128: Create soa_snapshot table for point-in-time SoA freeze + as-of-date cert bundle export.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS soa_snapshot (
    id INT AUTO_INCREMENT NOT NULL,
    tenant_id INT NOT NULL,
    created_by_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    as_of_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
    purpose VARCHAR(255) DEFAULT NULL,
    payload JSON NOT NULL,
    checksum_sha256 VARCHAR(64) NOT NULL,
    notes LONGTEXT DEFAULT NULL,
    INDEX idx_soa_snapshot_tenant_asof (tenant_id, as_of_date),
    INDEX idx_soa_snapshot_created_at (created_at),
    INDEX idx_soa_snapshot_created_by (created_by_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_soa_snapshot_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
    CONSTRAINT FK_soa_snapshot_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS soa_snapshot');
    }
}
