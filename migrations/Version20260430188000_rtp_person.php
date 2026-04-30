<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RiskTreatmentPlan: add Tri-State Person ownership for `responsiblePerson` slot.
 *
 * DB table: risk_treatment_plan (confirmed via default Doctrine naming)
 *
 * The existing `responsible_person_id` column stays in place (maps to the renamed
 * PHP field `$responsiblePersonUser`). Only additive DDL is needed.
 *
 * Adds:
 *   - responsible_person_person_id FK → person
 *   - join table rtp_responsible_deputy
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430188000_rtp_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RiskTreatmentPlan Tri-State Person ownership: responsiblePerson Person FK + deputy join table.';
    }

    public function up(Schema $schema): void
    {
        // New Person FK for responsible person slot (existing responsible_person_id stays)
        $this->addSql('ALTER TABLE risk_treatment_plan ADD responsible_person_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE risk_treatment_plan ADD CONSTRAINT fk_rtp_responsible_person '
            . 'FOREIGN KEY (responsible_person_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_rtp_responsible_person ON risk_treatment_plan (responsible_person_person_id)');

        // Deputies join table
        $this->addSql(
            'CREATE TABLE rtp_responsible_deputy ('
            . '  risk_treatment_plan_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_rtp_dep_plan (risk_treatment_plan_id), '
            . '  INDEX idx_rtp_dep_person (person_id), '
            . '  PRIMARY KEY(risk_treatment_plan_id, person_id), '
            . '  CONSTRAINT fk_rtp_dep_plan FOREIGN KEY (risk_treatment_plan_id) REFERENCES risk_treatment_plan (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_rtp_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rtp_responsible_deputy');

        $this->addSql('ALTER TABLE risk_treatment_plan DROP FOREIGN KEY fk_rtp_responsible_person');
        $this->addSql('DROP INDEX idx_rtp_responsible_person ON risk_treatment_plan');
        $this->addSql('ALTER TABLE risk_treatment_plan DROP COLUMN responsible_person_person_id');
    }
}
