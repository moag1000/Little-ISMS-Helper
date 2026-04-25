<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BSI 3.6: asset_dependencies join table for Schutzbedarfsvererbung
 * (Maximumprinzip along Asset.dependsOn graph).
 */
final class Version20260418160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BSI 3.6: asset_dependencies join table (self-referencing ManyToMany)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS asset_dependencies (
            dependent_asset_id INT NOT NULL,
            depends_on_asset_id INT NOT NULL,
            INDEX idx_asset_dep_dependent (dependent_asset_id),
            INDEX idx_asset_dep_depends_on (depends_on_asset_id),
            PRIMARY KEY (dependent_asset_id, depends_on_asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("SET @fk1_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='asset_dependencies' AND CONSTRAINT_NAME='FK_ASSET_DEP_DEPENDENT')");
        $this->addSql("SET @sql := IF(@fk1_exists = 0, 'ALTER TABLE asset_dependencies ADD CONSTRAINT FK_ASSET_DEP_DEPENDENT FOREIGN KEY (dependent_asset_id) REFERENCES asset (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @fk2_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='asset_dependencies' AND CONSTRAINT_NAME='FK_ASSET_DEP_DEPENDS_ON')");
        $this->addSql("SET @sql := IF(@fk2_exists = 0, 'ALTER TABLE asset_dependencies ADD CONSTRAINT FK_ASSET_DEP_DEPENDS_ON FOREIGN KEY (depends_on_asset_id) REFERENCES asset (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset_dependencies DROP FOREIGN KEY FK_ASSET_DEP_DEPENDENT');
        $this->addSql('ALTER TABLE asset_dependencies DROP FOREIGN KEY FK_ASSET_DEP_DEPENDS_ON');
        $this->addSql('DROP TABLE asset_dependencies');
    }
}
