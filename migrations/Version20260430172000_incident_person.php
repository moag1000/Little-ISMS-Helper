<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Incident: add `reported_by_person_id` (FK Person) and deputies join table
 * `incident_reporter_deputy`. Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430172000_incident_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incident Tri-State Person ownership: reported_by_person_id FK + incident_reporter_deputy join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD reported_by_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE incident ADD CONSTRAINT fk_incident_reporter_person '
            . 'FOREIGN KEY (reported_by_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_incident_reporter_person ON incident (reported_by_person_id)');

        $this->addSql(
            'CREATE TABLE incident_reporter_deputy ('
            . '  incident_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_incident_dep_incident (incident_id), '
            . '  INDEX idx_incident_dep_person (person_id), '
            . '  PRIMARY KEY(incident_id, person_id), '
            . '  CONSTRAINT fk_incident_dep_incident FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_incident_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE incident_reporter_deputy');
        $this->addSql('ALTER TABLE incident DROP FOREIGN KEY fk_incident_reporter_person');
        $this->addSql('DROP INDEX idx_incident_reporter_person ON incident');
        $this->addSql('ALTER TABLE incident DROP COLUMN reported_by_person_id');
    }
}
