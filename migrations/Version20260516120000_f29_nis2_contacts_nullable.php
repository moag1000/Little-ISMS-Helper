<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F29 fix — Make NIS-2 registration contact columns nullable.
 *
 * The original migration (Version20260514110000) created
 * incident_reporting_contact_id and security_responsible_contact_id as NOT NULL.
 * This caused a SQLSTATE[23000] 1048 crash whenever getOrCreateProfile() auto-
 * created a fresh profile for a tenant that has no users yet, or where no suitable
 * contact had been designated.
 *
 * Fix: change both FK columns to NULL DEFAULT NULL and switch onDelete from
 * RESTRICT to SET NULL so that user deletion is handled gracefully.
 * Validation (required contacts) is now enforced at form-submission time via
 * Nis2BsiRegistrationService::validate() instead of at DB write time.
 *
 * isTransactional() = false: DDL on MySQL/MariaDB implicitly commits;
 * keeping it transactional causes SAVEPOINT failures in doctrine:migrations:migrate.
 */
final class Version20260516120000_f29_nis2_contacts_nullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F29 fix: make nis2_registration_profile contact FK columns nullable (validate at form-submit instead of DB-level)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Drop old RESTRICT FK constraints before altering the columns
        $this->addSql('ALTER TABLE nis2_registration_profile DROP FOREIGN KEY fk_nis2_profile_incident_contact');
        $this->addSql('ALTER TABLE nis2_registration_profile DROP FOREIGN KEY fk_nis2_profile_security_contact');

        // Change columns from NOT NULL to NULL DEFAULT NULL
        $this->addSql('ALTER TABLE nis2_registration_profile MODIFY COLUMN incident_reporting_contact_id INT NULL DEFAULT NULL');
        $this->addSql('ALTER TABLE nis2_registration_profile MODIFY COLUMN security_responsible_contact_id INT NULL DEFAULT NULL');

        // Re-add FK constraints with SET NULL on delete (mirrors backup_security_contact pattern)
        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT fk_nis2_profile_incident_contact
                    FOREIGN KEY (incident_reporting_contact_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT fk_nis2_profile_security_contact
                    FOREIGN KEY (security_responsible_contact_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Note: down() will fail if any rows have NULL contacts — acceptable,
        // as rolling back to NOT NULL on a live table with NULL rows is unsafe.
        $this->addSql('ALTER TABLE nis2_registration_profile DROP FOREIGN KEY fk_nis2_profile_incident_contact');
        $this->addSql('ALTER TABLE nis2_registration_profile DROP FOREIGN KEY fk_nis2_profile_security_contact');

        $this->addSql('ALTER TABLE nis2_registration_profile MODIFY COLUMN incident_reporting_contact_id INT NOT NULL');
        $this->addSql('ALTER TABLE nis2_registration_profile MODIFY COLUMN security_responsible_contact_id INT NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT fk_nis2_profile_incident_contact
                    FOREIGN KEY (incident_reporting_contact_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT fk_nis2_profile_security_contact
                    FOREIGN KEY (security_responsible_contact_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT
        SQL);
    }
}
