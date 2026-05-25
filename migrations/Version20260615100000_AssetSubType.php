<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S18 B2 — Tenant-configurable AssetSubType layer.
 *
 * Creates `asset_sub_type` table with FK→tenant and unique constraint per
 * tenant+top_type+name. Adds `sub_type_id` FK column on `asset` (additive,
 * nullable, no data migration of existing rows — Asset.assetType top-level
 * stays untouched).
 *
 * `isTransactional() = false` per CLAUDE.md pitfall #6 (multi-DDL).
 */
final class Version20260615100000_AssetSubType extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'S18 B2: AssetSubType table + Asset.sub_type_id FK (tenant-configurable sub-type layer).';
    }

    public function up(Schema $schema): void
    {
        // 1) Create asset_sub_type table.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS asset_sub_type (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                top_type VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                source VARCHAR(40) DEFAULT 'custom',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_asset_sub_type_tenant (tenant_id),
                INDEX idx_asset_sub_type_top_type (top_type),
                UNIQUE INDEX uniq_subtype_per_tenant (tenant_id, top_type, name),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 2) FK tenant_id → tenant(id) ON DELETE CASCADE.
        $this->addSql(<<<'SQL'
            ALTER TABLE asset_sub_type
            ADD CONSTRAINT FK_asset_sub_type_tenant
            FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        // 3) Add sub_type_id column to asset.
        $this->addSql(<<<'SQL'
            ALTER TABLE asset
            ADD COLUMN IF NOT EXISTS sub_type_id INT DEFAULT NULL
        SQL);

        // 4) FK + index for sub_type_id.
        $this->addSql(<<<'SQL'
            ALTER TABLE asset
            ADD CONSTRAINT FK_asset_sub_type
            FOREIGN KEY (sub_type_id) REFERENCES asset_sub_type (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_asset_sub_type ON asset (sub_type_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_asset_sub_type ON asset');
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY FK_asset_sub_type');
        $this->addSql('ALTER TABLE asset DROP COLUMN sub_type_id');
        $this->addSql('DROP TABLE IF EXISTS asset_sub_type');
    }
}
