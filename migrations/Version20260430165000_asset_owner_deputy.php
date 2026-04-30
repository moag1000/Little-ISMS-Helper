<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Asset: add `asset_owner_deputy` join table for n-deputies.
 * ownerPerson FK (owner_person_id) already present from Version20260430140000.
 *
 * Plain ALTER TABLE / CREATE TABLE — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430165000_asset_owner_deputy extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Asset Tri-State Person ownership: add ownerDeputyPersons join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE asset_owner_deputy ('
            . '  asset_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_asset_deputy_asset (asset_id), '
            . '  INDEX idx_asset_deputy_person (person_id), '
            . '  PRIMARY KEY(asset_id, person_id), '
            . '  CONSTRAINT fk_asset_owner_deputy_asset FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_asset_owner_deputy_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE asset_owner_deputy');
    }
}
