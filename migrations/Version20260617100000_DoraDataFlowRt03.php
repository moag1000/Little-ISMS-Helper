<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bucket-6 (DORA RoI) — RT_03 data-flow sub-table.
 *
 * Adds the `dora_data_flow` table backing the {@see App\Entity\DoraDataFlow}
 * entity which closes the RT_03 deferred-marker in
 * {@see App\Service\Authority\DoraRoiXbrlExporter}.
 *
 * Per ESA RT_03 spec:
 *   - id, tenant_id (FK → tenant.id, ON DELETE CASCADE)
 *   - supplier_id (FK → supplier.id, ON DELETE CASCADE, NOT NULL)
 *   - data_categories JSON (PII / financial / health / ...)
 *   - direction VARCHAR(20) — 'inbound' / 'outbound' / 'bidirectional'
 *   - processing_purpose TEXT (max 500 chars enforced form-side)
 *   - security_measures JSON (encryption / tokenisation / ...)
 *   - data_volume VARCHAR(100)
 *   - cross_border TINYINT(1)
 *   - receiving_country CHAR(2) — ISO 3166-1 alpha-2
 *   - created_at / updated_at
 *
 * Indices: tenant_id, supplier_id, direction.
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 — MySQL `CREATE TABLE`
 * commits implicitly and invalidates the Doctrine SAVEPOINT, breaking any
 * subsequent migration in the same `migrate` run.
 */
final class Version20260617100000_DoraDataFlowRt03 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Bucket-6: add dora_data_flow table (DORA RoI RT_03 sub-table) backing DoraDataFlow entity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS dora_data_flow ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'tenant_id INT DEFAULT NULL, '
            . 'supplier_id INT NOT NULL, '
            . 'data_categories JSON NOT NULL, '
            . 'direction VARCHAR(20) NOT NULL, '
            . 'processing_purpose LONGTEXT DEFAULT NULL, '
            . 'security_measures JSON NOT NULL, '
            . 'data_volume VARCHAR(100) DEFAULT NULL, '
            . 'cross_border TINYINT(1) NOT NULL DEFAULT 0, '
            . 'receiving_country VARCHAR(2) DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'INDEX idx_dora_data_flow_tenant (tenant_id), '
            . 'INDEX idx_dora_data_flow_supplier (supplier_id), '
            . 'INDEX idx_dora_data_flow_direction (direction), '
            . 'PRIMARY KEY (id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE dora_data_flow '
            . 'ADD CONSTRAINT FK_dora_data_flow_tenant '
            . 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'ALTER TABLE dora_data_flow '
            . 'ADD CONSTRAINT FK_dora_data_flow_supplier '
            . 'FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dora_data_flow DROP FOREIGN KEY FK_dora_data_flow_tenant');
        $this->addSql('ALTER TABLE dora_data_flow DROP FOREIGN KEY FK_dora_data_flow_supplier');
        $this->addSql('DROP TABLE IF EXISTS dora_data_flow');
    }
}
