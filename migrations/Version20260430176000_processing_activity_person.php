<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ProcessingActivity: add Tri-State Person ownership for `contactPerson` and
 * `dataProtectionOfficer` slots.
 *
 * DB table: processing_activity (confirmed via #[ORM\Table]).
 *
 * The existing `contact_person_id` column stays in place (maps to the renamed
 * PHP field `$contactPersonUser`). Only additive DDL is needed for new columns.
 *
 * Adds:
 *   - contact_person_person_id FK → person  (new Person slot for contact)
 *   - data_protection_officer_person_id FK → person
 *   - join table processing_activity_contact_deputy
 *   - join table processing_activity_dpo_deputy
 *
 * Plain DDL — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 */
final class Version20260430176000_processing_activity_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ProcessingActivity Tri-State Person ownership: contactPerson + DPO Person FKs + deputy join tables.';
    }

    public function up(Schema $schema): void
    {
        // Contact Person slot (new Person FK — existing contact_person_id stays)
        $this->addSql('ALTER TABLE processing_activity ADD contact_person_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE processing_activity ADD CONSTRAINT fk_pa_contact_person '
            . 'FOREIGN KEY (contact_person_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_pa_contact_person ON processing_activity (contact_person_person_id)');

        // DPO Person slot
        $this->addSql('ALTER TABLE processing_activity ADD data_protection_officer_person_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE processing_activity ADD CONSTRAINT fk_pa_dpo_person '
            . 'FOREIGN KEY (data_protection_officer_person_id) REFERENCES person (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_pa_dpo_person ON processing_activity (data_protection_officer_person_id)');

        // Contact person deputies join table
        $this->addSql(
            'CREATE TABLE processing_activity_contact_deputy ('
            . '  processing_activity_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_pa_cont_dep_pa (processing_activity_id), '
            . '  INDEX idx_pa_cont_dep_person (person_id), '
            . '  PRIMARY KEY(processing_activity_id, person_id), '
            . '  CONSTRAINT fk_pa_cont_dep_pa FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_pa_cont_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // DPO deputies join table
        $this->addSql(
            'CREATE TABLE processing_activity_dpo_deputy ('
            . '  processing_activity_id INT NOT NULL, '
            . '  person_id INT NOT NULL, '
            . '  INDEX idx_pa_dpo_dep_pa (processing_activity_id), '
            . '  INDEX idx_pa_dpo_dep_person (person_id), '
            . '  PRIMARY KEY(processing_activity_id, person_id), '
            . '  CONSTRAINT fk_pa_dpo_dep_pa FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE CASCADE, '
            . '  CONSTRAINT fk_pa_dpo_dep_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processing_activity_dpo_deputy');
        $this->addSql('DROP TABLE processing_activity_contact_deputy');

        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY fk_pa_dpo_person');
        $this->addSql('DROP INDEX idx_pa_dpo_person ON processing_activity');
        $this->addSql('ALTER TABLE processing_activity DROP COLUMN data_protection_officer_person_id');

        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY fk_pa_contact_person');
        $this->addSql('DROP INDEX idx_pa_contact_person ON processing_activity');
        $this->addSql('ALTER TABLE processing_activity DROP COLUMN contact_person_person_id');
    }
}
