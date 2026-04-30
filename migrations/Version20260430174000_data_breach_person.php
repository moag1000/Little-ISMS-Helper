<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DataBreach: add Tri-State Person ownership for `dataProtectionOfficer` and
 * `assessor` slots.
 *
 * Adds:
 *   - data_protection_officer_person_id FK → person (data_breach table)
 *   - assessor_person_id FK → person (data_breach table)
 *   - join table data_breach_dpo_deputy
 *   - join table data_breach_assessor_deputy
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430174000_data_breach_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DataBreach Tri-State Person ownership: DPO + assessor Person FKs + deputy join tables.';
    }

    public function up(Schema $schema): void
    {
        // DPO Person slot
        $this->addSql('ALTER TABLE data_breach ADD data_protection_officer_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE data_breach ADD CONSTRAINT fk_breach_dpo_person '
            . 'FOREIGN KEY (data_protection_officer_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_breach_dpo_person ON data_breach (data_protection_officer_person_id)');

        // Assessor Person slot
        $this->addSql('ALTER TABLE data_breach ADD assessor_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE data_breach ADD CONSTRAINT fk_breach_assessor_person '
            . 'FOREIGN KEY (assessor_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_breach_assessor_person ON data_breach (assessor_person_id)');

        // DPO deputies join table
        $this->addSql(
            'CREATE TABLE data_breach_dpo_deputy ('
            . '  data_breach_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_breach_dpo_dep_breach (data_breach_id), '
            . '  INDEX idx_breach_dpo_dep_person (person_id), '
            . '  PRIMARY KEY(data_breach_id, person_id), '
            . '  CONSTRAINT fk_breach_dpo_dep_breach FOREIGN KEY (data_breach_id) REFERENCES data_breach (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_breach_dpo_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Assessor deputies join table
        $this->addSql(
            'CREATE TABLE data_breach_assessor_deputy ('
            . '  data_breach_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_breach_assr_dep_breach (data_breach_id), '
            . '  INDEX idx_breach_assr_dep_person (person_id), '
            . '  PRIMARY KEY(data_breach_id, person_id), '
            . '  CONSTRAINT fk_breach_assr_dep_breach FOREIGN KEY (data_breach_id) REFERENCES data_breach (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_breach_assr_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE data_breach_assessor_deputy');
        $this->addSql('DROP TABLE data_breach_dpo_deputy');

        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY fk_breach_assessor_person');
        $this->addSql('DROP INDEX idx_breach_assessor_person ON data_breach');
        $this->addSql('ALTER TABLE data_breach DROP COLUMN assessor_person_id');

        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY fk_breach_dpo_person');
        $this->addSql('DROP INDEX idx_breach_dpo_person ON data_breach');
        $this->addSql('ALTER TABLE data_breach DROP COLUMN data_protection_officer_person_id');
    }
}
