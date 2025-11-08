<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Entity Property Fixes
 *
 * Adds the following columns:
 * - asset.acquisition_value (DECIMAL 10,2): Asset acquisition/purchase value
 * - asset.current_value (DECIMAL 10,2): Current asset value
 * - incident.affected_systems (TEXT): Systems affected by incident
 */
final class Version20251108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add acquisition_value, current_value to asset table and affected_systems to incident table';
    }

    public function up(Schema $schema): void
    {
        // Asset table: Add acquisition_value and current_value
        $this->addSql('ALTER TABLE asset ADD acquisition_value NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset ADD current_value NUMERIC(10, 2) DEFAULT NULL');

        // Incident table: Add affected_systems
        $this->addSql('ALTER TABLE incident ADD affected_systems TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Rollback: Remove added columns
        $this->addSql('ALTER TABLE asset DROP acquisition_value');
        $this->addSql('ALTER TABLE asset DROP current_value');
        $this->addSql('ALTER TABLE incident DROP affected_systems');
    }
}
