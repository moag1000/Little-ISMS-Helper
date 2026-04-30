<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DataProtectionImpactAssessment: add Tri-State Person ownership for
 * `dataProtectionOfficer`, `conductor`, and `approver` slots.
 *
 * DB table: data_protection_impact_assessment (confirmed via #[ORM\Table]).
 *
 * Adds:
 *   - data_protection_officer_person_id FK → person
 *   - conductor_person_id FK → person
 *   - approver_person_id FK → person
 *   - join table dpia_dpo_deputy
 *   - join table dpia_conductor_deputy
 *   - join table dpia_approver_deputy
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430175000_dpia_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DPIA Tri-State Person ownership: DPO + conductor + approver Person FKs + deputy join tables.';
    }

    public function up(Schema $schema): void
    {
        // DPO Person slot
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD data_protection_officer_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT fk_dpia_dpo_person '
            . 'FOREIGN KEY (data_protection_officer_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_dpia_dpo_person ON data_protection_impact_assessment (data_protection_officer_person_id)');

        // Conductor Person slot
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD conductor_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT fk_dpia_conductor_person '
            . 'FOREIGN KEY (conductor_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_dpia_conductor_person ON data_protection_impact_assessment (conductor_person_id)');

        // Approver Person slot
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD approver_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT fk_dpia_approver_person '
            . 'FOREIGN KEY (approver_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_dpia_approver_person ON data_protection_impact_assessment (approver_person_id)');

        // DPO deputies join table
        $this->addSql(
            'CREATE TABLE dpia_dpo_deputy ('
            . '  dpia_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_dpia_dpo_dep_dpia (dpia_id), '
            . '  INDEX idx_dpia_dpo_dep_person (person_id), '
            . '  PRIMARY KEY(dpia_id, person_id), '
            . '  CONSTRAINT fk_dpia_dpo_dep_dpia FOREIGN KEY (dpia_id) REFERENCES data_protection_impact_assessment (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_dpia_dpo_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Conductor deputies join table
        $this->addSql(
            'CREATE TABLE dpia_conductor_deputy ('
            . '  dpia_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_dpia_cond_dep_dpia (dpia_id), '
            . '  INDEX idx_dpia_cond_dep_person (person_id), '
            . '  PRIMARY KEY(dpia_id, person_id), '
            . '  CONSTRAINT fk_dpia_cond_dep_dpia FOREIGN KEY (dpia_id) REFERENCES data_protection_impact_assessment (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_dpia_cond_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Approver deputies join table
        $this->addSql(
            'CREATE TABLE dpia_approver_deputy ('
            . '  dpia_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_dpia_appr_dep_dpia (dpia_id), '
            . '  INDEX idx_dpia_appr_dep_person (person_id), '
            . '  PRIMARY KEY(dpia_id, person_id), '
            . '  CONSTRAINT fk_dpia_appr_dep_dpia FOREIGN KEY (dpia_id) REFERENCES data_protection_impact_assessment (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_dpia_appr_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dpia_approver_deputy');
        $this->addSql('DROP TABLE dpia_conductor_deputy');
        $this->addSql('DROP TABLE dpia_dpo_deputy');

        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY fk_dpia_approver_person');
        $this->addSql('DROP INDEX idx_dpia_approver_person ON data_protection_impact_assessment');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP COLUMN approver_person_id');

        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY fk_dpia_conductor_person');
        $this->addSql('DROP INDEX idx_dpia_conductor_person ON data_protection_impact_assessment');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP COLUMN conductor_person_id');

        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY fk_dpia_dpo_person');
        $this->addSql('DROP INDEX idx_dpia_dpo_person ON data_protection_impact_assessment');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP COLUMN data_protection_officer_person_id');
    }
}
