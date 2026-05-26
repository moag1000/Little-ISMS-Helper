<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bucket-6 (DORA RoI Sprint 9, RT_05 asset-dependency-graph) —
 * add the `asset_dependency` join table.
 *
 * Sits next to the legacy `asset_dependencies` ManyToMany junction table
 * (kept intact for BSI 3.6 Schutzbedarfsvererbung and GstoolXmlImporter
 * backward-compatibility). The new table carries the per-edge attributes
 * the ESA RoI XBRL exporter needs to emit per-edge sub-elements for DORA
 * Art. 28(3)(c):
 *
 *  - dependency_type: requires | backs_up | shares_data | redundant_with
 *  - criticality_impact: cascade | isolated | partial
 *  - notes: free-text description (e.g. "DB connection via VPN")
 *
 * Unique index `(source_asset_id, target_asset_id)` prevents duplicate
 * edges with conflicting metadata.
 *
 * `isTransactional()=false` per CLAUDE.md pitfall #6 — `CREATE TABLE`
 * commits implicitly under MySQL and would invalidate Doctrine's
 * per-migration SAVEPOINT if run alongside other DDL migrations.
 */
final class Version20260617100000_AssetDependencyEnrichedEdges extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Bucket-6 RT_05: add asset_dependency join table for DORA RoI per-edge enrichment (dependency_type + criticality_impact).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS asset_dependency ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'source_asset_id INT NOT NULL, '
            . 'target_asset_id INT NOT NULL, '
            . 'dependency_type VARCHAR(32) NOT NULL DEFAULT \'requires\', '
            . 'criticality_impact VARCHAR(32) NOT NULL DEFAULT \'cascade\', '
            . 'notes LONGTEXT DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'UNIQUE INDEX uniq_asset_dependency_edge (source_asset_id, target_asset_id), '
            . 'INDEX idx_asset_dependency_source (source_asset_id), '
            . 'INDEX idx_asset_dependency_target (target_asset_id), '
            . 'PRIMARY KEY (id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE asset_dependency '
            . 'ADD CONSTRAINT FK_asset_dependency_source FOREIGN KEY (source_asset_id) REFERENCES asset (id) ON DELETE CASCADE, '
            . 'ADD CONSTRAINT FK_asset_dependency_target FOREIGN KEY (target_asset_id) REFERENCES asset (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS asset_dependency');
    }
}
