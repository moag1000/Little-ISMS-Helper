<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add location_id foreign keys to asset and physical_access_log
 *
 * Links assets and physical access logs to the centralized Location entity.
 * The location string fields are kept for backwards compatibility (deprecated).
 */
final class Version20251108000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add location_id foreign keys to asset and physical_access_log tables';
    }

    public function up(Schema $schema): void
    {
        // Add location_id column to asset
        $this->addSql('ALTER TABLE asset ADD location_entity_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD INDEX IDX_ASSET_LOCATION (location_entity_id)');
        $this->addSql('ALTER TABLE asset
            ADD CONSTRAINT FK_ASSET_LOCATION FOREIGN KEY (location_entity_id) REFERENCES location (id)');

        // Add location_entity_id column to physical_access_log
        $this->addSql('ALTER TABLE physical_access_log ADD location_entity_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE physical_access_log ADD INDEX IDX_PHYSICAL_LOCATION_ENTITY (location_entity_id)');
        $this->addSql('ALTER TABLE physical_access_log
            ADD CONSTRAINT FK_PHYSICAL_LOCATION FOREIGN KEY (location_entity_id) REFERENCES location (id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys and columns from physical_access_log
        $this->addSql('ALTER TABLE physical_access_log DROP FOREIGN KEY FK_PHYSICAL_LOCATION');
        $this->addSql('DROP INDEX IDX_PHYSICAL_LOCATION_ENTITY ON physical_access_log');
        $this->addSql('ALTER TABLE physical_access_log DROP location_entity_id');

        // Drop foreign keys and columns from asset
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY FK_ASSET_LOCATION');
        $this->addSql('DROP INDEX IDX_ASSET_LOCATION ON asset');
        $this->addSql('ALTER TABLE asset DROP location_entity_id');
    }
}
