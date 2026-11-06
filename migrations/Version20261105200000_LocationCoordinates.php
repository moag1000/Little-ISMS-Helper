<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Location map: optional WGS84 coordinates and their source on location.
 */
final class Version20261105200000_LocationCoordinates extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Location: add latitude, longitude and geo_source for the location map';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL, ADD geo_source VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location DROP latitude, DROP longitude, DROP geo_source');
    }
}
