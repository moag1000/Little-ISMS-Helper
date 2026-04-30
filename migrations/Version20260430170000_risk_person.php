<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Risk: add `risk_owner_person_id` (FK Person) and deputies join table
 * `risk_owner_deputy`. Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430170000_risk_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Risk Tri-State Person ownership: risk_owner_person_id FK + risk_owner_deputy join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk ADD risk_owner_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE risk ADD CONSTRAINT fk_risk_owner_person '
            . 'FOREIGN KEY (risk_owner_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_risk_owner_person ON risk (risk_owner_person_id)');

        $this->addSql(
            'CREATE TABLE risk_owner_deputy ('
            . '  risk_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_risk_deputy_risk (risk_id), '
            . '  INDEX idx_risk_deputy_person (person_id), '
            . '  PRIMARY KEY(risk_id, person_id), '
            . '  CONSTRAINT fk_risk_deputy_risk FOREIGN KEY (risk_id) REFERENCES risk (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_risk_deputy_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE risk_owner_deputy');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY fk_risk_owner_person');
        $this->addSql('DROP INDEX idx_risk_owner_person ON risk');
        $this->addSql('ALTER TABLE risk DROP COLUMN risk_owner_person_id');
    }
}
