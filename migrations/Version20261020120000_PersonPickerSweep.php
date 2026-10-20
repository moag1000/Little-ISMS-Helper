<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Person-Picker sweep — extend "Pattern A Dual-State Owner" to the remaining
 * 6 entities whose person field was still free-text only:
 *   ISMSObjective, InterestedParty, Location, Patch, Supplier, Vulnerability.
 *
 * Each gains a structured User-FK (<base>_person_user_id), a Person-FK
 * (<base>_person_ref_id) and a deputy M2M join-table. The legacy free-text
 * column is kept untouched for round-trip during the migration window.
 *
 * onDelete: SET NULL on the owner FKs (never block User/Person deletion);
 * CASCADE on the deputy join-tables.
 */
final class Version20261020120000_PersonPickerSweep extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Person-picker dual-state owner cluster for ISMSObjective, InterestedParty, Location, Patch, Supplier, Vulnerability';
    }

    public function isTransactional(): bool
    {
        // Contains ALTER TABLE + CREATE TABLE — each commits implicitly in
        // MySQL, which invalidates Doctrine's per-migration SAVEPOINT.
        return false;
    }

    public function up(Schema $schema): void
    {
        // ── ISMSObjective (table: ismsobjective) ──
        // Free-text owner becomes optional (a structured owner may replace it).
        $this->addSql('ALTER TABLE ismsobjective MODIFY responsible_person VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE ismsobjective ADD responsible_person_user_id INT DEFAULT NULL, ADD responsible_person_ref_id INT DEFAULT NULL');
        $this->addSql('CREATE TABLE isms_objective_responsible_deputy (objective_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_1D01C54473484933 (objective_id), INDEX IDX_1D01C544217BBB47 (person_id), PRIMARY KEY (objective_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE isms_objective_responsible_deputy ADD CONSTRAINT FK_1D01C54473484933 FOREIGN KEY (objective_id) REFERENCES ismsobjective (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE isms_objective_responsible_deputy ADD CONSTRAINT FK_1D01C544217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ismsobjective ADD CONSTRAINT FK_C67C53BC54519FE FOREIGN KEY (responsible_person_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ismsobjective ADD CONSTRAINT FK_C67C53BD760F5F5 FOREIGN KEY (responsible_person_ref_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C67C53BC54519FE ON ismsobjective (responsible_person_user_id)');
        $this->addSql('CREATE INDEX IDX_C67C53BD760F5F5 ON ismsobjective (responsible_person_ref_id)');

        // ── InterestedParty (table: interested_party) ──
        $this->addSql('ALTER TABLE interested_party ADD contact_person_user_id INT DEFAULT NULL, ADD contact_person_ref_id INT DEFAULT NULL');
        $this->addSql('CREATE TABLE interested_party_contact_deputy (interested_party_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_A610BEB353295225 (interested_party_id), INDEX IDX_A610BEB3217BBB47 (person_id), PRIMARY KEY (interested_party_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE interested_party_contact_deputy ADD CONSTRAINT FK_A610BEB353295225 FOREIGN KEY (interested_party_id) REFERENCES interested_party (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interested_party_contact_deputy ADD CONSTRAINT FK_A610BEB3217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interested_party ADD CONSTRAINT FK_4B8D8C2CF60DEEDC FOREIGN KEY (contact_person_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE interested_party ADD CONSTRAINT FK_4B8D8C2C9C8A3298 FOREIGN KEY (contact_person_ref_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4B8D8C2CF60DEEDC ON interested_party (contact_person_user_id)');
        $this->addSql('CREATE INDEX IDX_4B8D8C2C9C8A3298 ON interested_party (contact_person_ref_id)');

        // ── Location (table: location) ──
        $this->addSql('ALTER TABLE location ADD responsible_person_user_id INT DEFAULT NULL, ADD responsible_person_ref_id INT DEFAULT NULL');
        $this->addSql('CREATE TABLE location_responsible_deputy (location_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_BE8699B164D218E (location_id), INDEX IDX_BE8699B1217BBB47 (person_id), PRIMARY KEY (location_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE location_responsible_deputy ADD CONSTRAINT FK_BE8699B164D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE location_responsible_deputy ADD CONSTRAINT FK_BE8699B1217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE location ADD CONSTRAINT FK_5E9E89CBC54519FE FOREIGN KEY (responsible_person_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE location ADD CONSTRAINT FK_5E9E89CBD760F5F5 FOREIGN KEY (responsible_person_ref_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5E9E89CBC54519FE ON location (responsible_person_user_id)');
        $this->addSql('CREATE INDEX IDX_5E9E89CBD760F5F5 ON location (responsible_person_ref_id)');

        // ── Patch (table: patches) ──
        $this->addSql('ALTER TABLE patches ADD responsible_person_user_id INT DEFAULT NULL, ADD responsible_person_ref_id INT DEFAULT NULL');
        $this->addSql('CREATE TABLE patch_responsible_deputy (patch_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_CEB97FA4CD00882C (patch_id), INDEX IDX_CEB97FA4217BBB47 (person_id), PRIMARY KEY (patch_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE patch_responsible_deputy ADD CONSTRAINT FK_CEB97FA4CD00882C FOREIGN KEY (patch_id) REFERENCES patches (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE patch_responsible_deputy ADD CONSTRAINT FK_CEB97FA4217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE patches ADD CONSTRAINT FK_2FE8B4C54519FE FOREIGN KEY (responsible_person_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE patches ADD CONSTRAINT FK_2FE8B4D760F5F5 FOREIGN KEY (responsible_person_ref_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2FE8B4C54519FE ON patches (responsible_person_user_id)');
        $this->addSql('CREATE INDEX IDX_2FE8B4D760F5F5 ON patches (responsible_person_ref_id)');

        // ── Supplier (table: supplier) ──
        $this->addSql('ALTER TABLE supplier ADD contact_person_user_id INT DEFAULT NULL, ADD contact_person_ref_id INT DEFAULT NULL');
        $this->addSql('CREATE TABLE supplier_contact_deputy (supplier_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_BD7AD68D2ADD6D8C (supplier_id), INDEX IDX_BD7AD68D217BBB47 (person_id), PRIMARY KEY (supplier_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE supplier_contact_deputy ADD CONSTRAINT FK_BD7AD68D2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_contact_deputy ADD CONSTRAINT FK_BD7AD68D217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier ADD CONSTRAINT FK_9B2A6C7EF60DEEDC FOREIGN KEY (contact_person_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE supplier ADD CONSTRAINT FK_9B2A6C7E9C8A3298 FOREIGN KEY (contact_person_ref_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9B2A6C7EF60DEEDC ON supplier (contact_person_user_id)');
        $this->addSql('CREATE INDEX IDX_9B2A6C7E9C8A3298 ON supplier (contact_person_ref_id)');

        // ── Vulnerability (table: vulnerabilities) ──
        $this->addSql('ALTER TABLE vulnerabilities ADD responsible_person_user_id INT DEFAULT NULL, ADD responsible_person_ref_id INT DEFAULT NULL');
        $this->addSql('CREATE TABLE vulnerability_responsible_deputy (vulnerability_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_AE98886B72897D8B (vulnerability_id), INDEX IDX_AE98886B217BBB47 (person_id), PRIMARY KEY (vulnerability_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE vulnerability_responsible_deputy ADD CONSTRAINT FK_AE98886B72897D8B FOREIGN KEY (vulnerability_id) REFERENCES vulnerabilities (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vulnerability_responsible_deputy ADD CONSTRAINT FK_AE98886B217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vulnerabilities ADD CONSTRAINT FK_459DFAF7C54519FE FOREIGN KEY (responsible_person_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vulnerabilities ADD CONSTRAINT FK_459DFAF7D760F5F5 FOREIGN KEY (responsible_person_ref_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_459DFAF7C54519FE ON vulnerabilities (responsible_person_user_id)');
        $this->addSql('CREATE INDEX IDX_459DFAF7D760F5F5 ON vulnerabilities (responsible_person_ref_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vulnerability_responsible_deputy');
        $this->addSql('DROP TABLE supplier_contact_deputy');
        $this->addSql('DROP TABLE patch_responsible_deputy');
        $this->addSql('DROP TABLE location_responsible_deputy');
        $this->addSql('DROP TABLE interested_party_contact_deputy');
        $this->addSql('DROP TABLE isms_objective_responsible_deputy');
        $this->addSql('ALTER TABLE ismsobjective DROP FOREIGN KEY FK_C67C53BC54519FE');
        $this->addSql('ALTER TABLE ismsobjective DROP FOREIGN KEY FK_C67C53BD760F5F5');
        $this->addSql('DROP INDEX IDX_C67C53BC54519FE ON ismsobjective');
        $this->addSql('DROP INDEX IDX_C67C53BD760F5F5 ON ismsobjective');
        $this->addSql('ALTER TABLE ismsobjective DROP responsible_person_user_id, DROP responsible_person_ref_id');
        $this->addSql("UPDATE ismsobjective SET responsible_person = '' WHERE responsible_person IS NULL");
        $this->addSql('ALTER TABLE ismsobjective MODIFY responsible_person VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE interested_party DROP FOREIGN KEY FK_4B8D8C2CF60DEEDC');
        $this->addSql('ALTER TABLE interested_party DROP FOREIGN KEY FK_4B8D8C2C9C8A3298');
        $this->addSql('DROP INDEX IDX_4B8D8C2CF60DEEDC ON interested_party');
        $this->addSql('DROP INDEX IDX_4B8D8C2C9C8A3298 ON interested_party');
        $this->addSql('ALTER TABLE interested_party DROP contact_person_user_id, DROP contact_person_ref_id');
        $this->addSql('ALTER TABLE location DROP FOREIGN KEY FK_5E9E89CBC54519FE');
        $this->addSql('ALTER TABLE location DROP FOREIGN KEY FK_5E9E89CBD760F5F5');
        $this->addSql('DROP INDEX IDX_5E9E89CBC54519FE ON location');
        $this->addSql('DROP INDEX IDX_5E9E89CBD760F5F5 ON location');
        $this->addSql('ALTER TABLE location DROP responsible_person_user_id, DROP responsible_person_ref_id');
        $this->addSql('ALTER TABLE patches DROP FOREIGN KEY FK_2FE8B4C54519FE');
        $this->addSql('ALTER TABLE patches DROP FOREIGN KEY FK_2FE8B4D760F5F5');
        $this->addSql('DROP INDEX IDX_2FE8B4C54519FE ON patches');
        $this->addSql('DROP INDEX IDX_2FE8B4D760F5F5 ON patches');
        $this->addSql('ALTER TABLE patches DROP responsible_person_user_id, DROP responsible_person_ref_id');
        $this->addSql('ALTER TABLE supplier DROP FOREIGN KEY FK_9B2A6C7EF60DEEDC');
        $this->addSql('ALTER TABLE supplier DROP FOREIGN KEY FK_9B2A6C7E9C8A3298');
        $this->addSql('DROP INDEX IDX_9B2A6C7EF60DEEDC ON supplier');
        $this->addSql('DROP INDEX IDX_9B2A6C7E9C8A3298 ON supplier');
        $this->addSql('ALTER TABLE supplier DROP contact_person_user_id, DROP contact_person_ref_id');
        $this->addSql('ALTER TABLE vulnerabilities DROP FOREIGN KEY FK_459DFAF7C54519FE');
        $this->addSql('ALTER TABLE vulnerabilities DROP FOREIGN KEY FK_459DFAF7D760F5F5');
        $this->addSql('DROP INDEX IDX_459DFAF7C54519FE ON vulnerabilities');
        $this->addSql('DROP INDEX IDX_459DFAF7D760F5F5 ON vulnerabilities');
        $this->addSql('ALTER TABLE vulnerabilities DROP responsible_person_user_id, DROP responsible_person_ref_id');
    }
}
