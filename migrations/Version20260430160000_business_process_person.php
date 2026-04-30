<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BusinessProcess: add `process_owner_person_id` (FK Person) and the
 * deputies join table `business_process_owner_deputy`. Reference
 * implementation for Plan B + C — every following entity uses this same
 * shape (one nullable FK column + one M:N join table).
 *
 * Plain ALTER TABLE / CREATE TABLE — see CLAUDE.md pitfall #6 (avoid
 * PREPARE/EXECUTE; Doctrine silently records as executed without DDL).
 */
final class Version20260430160000_business_process_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BusinessProcess Tri-State Person ownership: process_owner_person_id FK + deputies join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_process ADD process_owner_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE business_process ADD CONSTRAINT fk_bp_owner_person '
            . 'FOREIGN KEY (process_owner_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_bp_owner_person ON business_process (process_owner_person_id)');

        $this->addSql(
            'CREATE TABLE business_process_owner_deputy ('
            . '  business_process_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_bp_deputy_bp (business_process_id), '
            . '  INDEX idx_bp_deputy_person (person_id), '
            . '  PRIMARY KEY(business_process_id, person_id), '
            . '  CONSTRAINT fk_bp_deputy_bp FOREIGN KEY (business_process_id) REFERENCES business_process (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_bp_deputy_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE business_process_owner_deputy');
        $this->addSql('ALTER TABLE business_process DROP FOREIGN KEY fk_bp_owner_person');
        $this->addSql('DROP INDEX idx_bp_owner_person ON business_process');
        $this->addSql('ALTER TABLE business_process DROP COLUMN process_owner_person_id');
    }
}
