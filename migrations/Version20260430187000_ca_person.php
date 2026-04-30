<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CorrectiveAction: add Tri-State Person ownership for `responsiblePerson` slot.
 *
 * DB table: corrective_actions
 *
 * The existing `responsible_person_id` column stays in place (maps to the renamed
 * PHP field `$responsiblePersonUser`). Only additive DDL is needed.
 *
 * Adds:
 *   - responsible_person_person_id FK → person
 *   - join table ca_responsible_deputy
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430187000_ca_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CorrectiveAction Tri-State Person ownership: responsiblePerson Person FK + deputy join table.';
    }

    public function up(Schema $schema): void
    {
        // New Person FK for responsible person slot (existing responsible_person_id stays)
        $this->addSql('ALTER TABLE corrective_actions ADD responsible_person_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE corrective_actions ADD CONSTRAINT fk_ca_responsible_person '
            . 'FOREIGN KEY (responsible_person_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_ca_responsible_person ON corrective_actions (responsible_person_person_id)');

        // Deputies join table
        $this->addSql(
            'CREATE TABLE ca_responsible_deputy ('
            . '  corrective_action_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_ca_dep_action (corrective_action_id), '
            . '  INDEX idx_ca_dep_person (person_id), '
            . '  PRIMARY KEY(corrective_action_id, person_id), '
            . '  CONSTRAINT fk_ca_dep_action FOREIGN KEY (corrective_action_id) REFERENCES corrective_actions (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_ca_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ca_responsible_deputy');

        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY fk_ca_responsible_person');
        $this->addSql('DROP INDEX idx_ca_responsible_person ON corrective_actions');
        $this->addSql('ALTER TABLE corrective_actions DROP COLUMN responsible_person_person_id');
    }
}
