<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ComplianceRequirementFulfillment: add Tri-State Person ownership for
 * `responsiblePerson` slot.
 *
 * DB table: compliance_requirement_fulfillment
 *
 * The existing `responsible_person_id` column stays in place (maps to the renamed
 * PHP field `$responsiblePersonUser`). Only additive DDL is needed.
 *
 * Adds:
 *   - responsible_person_person_id FK → person
 *   - join table crf_responsible_deputy
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430186000_crf_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ComplianceRequirementFulfillment Tri-State Person ownership: responsiblePerson Person FK + deputy join table.';
    }

    public function up(Schema $schema): void
    {
        // New Person FK for responsible person slot (existing responsible_person_id stays)
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD responsible_person_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT fk_crf_responsible_person '
            . 'FOREIGN KEY (responsible_person_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_crf_responsible_person ON compliance_requirement_fulfillment (responsible_person_person_id)');

        // Deputies join table
        $this->addSql(
            'CREATE TABLE crf_responsible_deputy ('
            . '  fulfillment_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_crf_dep_fulfillment (fulfillment_id), '
            . '  INDEX idx_crf_dep_person (person_id), '
            . '  PRIMARY KEY(fulfillment_id, person_id), '
            . '  CONSTRAINT fk_crf_dep_fulfillment FOREIGN KEY (fulfillment_id) REFERENCES compliance_requirement_fulfillment (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_crf_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crf_responsible_deputy');

        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY fk_crf_responsible_person');
        $this->addSql('DROP INDEX idx_crf_responsible_person ON compliance_requirement_fulfillment');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP COLUMN responsible_person_person_id');
    }
}
