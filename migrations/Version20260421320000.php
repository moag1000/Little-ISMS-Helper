<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 8QW-5 — SupplierCriticalityLevel pro Tenant.
 *
 * Erstellt die Tabelle supplier_criticality_level und befüllt für jeden
 * bestehenden Tenant 4 Default-Stufen (critical/high/medium/low) — identisch
 * zu den in TenantCreatedSeedListener geseedeten Werten.
 *
 * Backfill ist idempotent via NOT EXISTS, sodass die Migration mehrfach
 * ausgeführt werden kann ohne Duplikate zu erzeugen.
 */
final class Version20260421320000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8QW-5: supplier_criticality_level table + backfill 4 defaults per tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE supplier_criticality_level (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                code VARCHAR(50) NOT NULL,
                label_de VARCHAR(100) NOT NULL,
                label_en VARCHAR(100) NOT NULL,
                sort_order SMALLINT NOT NULL DEFAULT 50,
                color VARCHAR(30) DEFAULT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE INDEX uniq_scl_tenant_code (tenant_id, code),
                INDEX idx_scl_tenant (tenant_id),
                INDEX idx_scl_sort (tenant_id, sort_order),
                INDEX idx_scl_active (tenant_id, is_active),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
        $this->addSql("
            ALTER TABLE supplier_criticality_level
            ADD CONSTRAINT FK_scl_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        ");

        // Backfill: 4 Default-Stufen für jeden bestehenden Tenant.
        // Idempotent via NOT EXISTS.
        $levels = [
            ['critical', 'Kritisch', 'Critical', 10, 'danger', 0],
            ['high', 'Hoch', 'High', 20, 'warning', 0],
            ['medium', 'Mittel', 'Medium', 30, 'info', 1],
            ['low', 'Gering', 'Low', 40, 'secondary', 0],
        ];

        foreach ($levels as [$code, $labelDe, $labelEn, $sort, $color, $isDefault]) {
            $this->addSql("
                INSERT INTO supplier_criticality_level (tenant_id, code, label_de, label_en, sort_order, color, is_default, is_active)
                SELECT t.id, '{$code}', '{$labelDe}', '{$labelEn}', {$sort}, '{$color}', {$isDefault}, 1
                FROM tenant t
                WHERE NOT EXISTS (
                    SELECT 1 FROM supplier_criticality_level scl
                    WHERE scl.tenant_id = t.id AND scl.code = '{$code}'
                )
            ");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE supplier_criticality_level');
    }
}
