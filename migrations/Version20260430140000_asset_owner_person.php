<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds asset.owner_person_id ManyToOne(Person) for the Pattern-A tri-state
 * owner chain (ownerUser → ownerPerson → legacy string). User-Story: external
 * stakeholders without a system login should still be assignable as asset
 * owners; the existing User-only relation excluded them.
 *
 * Plain ALTER TABLE on purpose — see CLAUDE.md pitfall #6 (avoid
 * PREPARE/EXECUTE-pattern; Doctrine silently records as executed without
 * actually running the DDL).
 */
final class Version20260430140000_asset_owner_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asset.owner_person_id (FK → person.id, nullable, ON DELETE SET NULL) for Pattern-A tri-state owner.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset ADD owner_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE asset ADD CONSTRAINT fk_asset_owner_person '
            . 'FOREIGN KEY (owner_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_asset_owner_person ON asset (owner_person_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY fk_asset_owner_person');
        $this->addSql('DROP INDEX idx_asset_owner_person ON asset');
        $this->addSql('ALTER TABLE asset DROP COLUMN owner_person_id');
    }
}
