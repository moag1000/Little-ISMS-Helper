<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Control: add `responsible_person_contact_id` (FK Person) and deputies join
 * table `control_responsible_deputy`. Named `_contact` to avoid collision with
 * existing legacy string column `responsible_person`.
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430171000_control_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Control Tri-State Person ownership: responsible_person_contact_id FK + control_responsible_deputy join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE control ADD responsible_person_contact_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE control ADD CONSTRAINT fk_control_resp_person '
            . 'FOREIGN KEY (responsible_person_contact_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_control_resp_person ON control (responsible_person_contact_id)');

        $this->addSql(
            'CREATE TABLE control_responsible_deputy ('
            . '  control_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_ctrl_deputy_ctrl (control_id), '
            . '  INDEX idx_ctrl_deputy_person (person_id), '
            . '  PRIMARY KEY(control_id, person_id), '
            . '  CONSTRAINT fk_ctrl_deputy_ctrl FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_ctrl_deputy_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE control_responsible_deputy');
        $this->addSql('ALTER TABLE control DROP FOREIGN KEY fk_control_resp_person');
        $this->addSql('DROP INDEX idx_control_resp_person ON control');
        $this->addSql('ALTER TABLE control DROP COLUMN responsible_person_contact_id');
    }
}
