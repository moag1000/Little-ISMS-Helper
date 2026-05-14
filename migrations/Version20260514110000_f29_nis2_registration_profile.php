<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F29 — NIS-2 BSI-Portal Yearly Re-Registration Profile.
 *
 * Creates the nis2_registration_profile table that stores the mandatory
 * BSI-Portal Pflichtfelder for BSIG § 33 NIS-2 entity registration.
 * One row per tenant; enforced via UNIQUE KEY on tenant_id.
 *
 * isTransactional() = false: DDL on MySQL/MariaDB implicitly commits;
 * keeping it transactional causes SAVEPOINT failures in doctrine:migrations:migrate.
 */
final class Version20260514110000_f29_nis2_registration_profile extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F29: Create nis2_registration_profile table for BSI-Portal yearly re-registration data';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS nis2_registration_profile (
                id                              INT NOT NULL AUTO_INCREMENT,
                tenant_id                       INT NOT NULL,
                incident_reporting_contact_id   INT NOT NULL,
                security_responsible_contact_id INT NOT NULL,
                backup_security_contact_id      INT NULL DEFAULT NULL,
                organization_legal_name         VARCHAR(255) NOT NULL,
                organization_legal_form         VARCHAR(100) NOT NULL,
                commercial_register_city        VARCHAR(255) NOT NULL,
                commercial_register_number      VARCHAR(100) NOT NULL,
                vat_id                          VARCHAR(50)  NULL DEFAULT NULL,
                nace_codes                      JSON         NOT NULL,
                nis2_sector                     VARCHAR(100) NOT NULL,
                nis2_entity_category            VARCHAR(20)  NOT NULL DEFAULT 'important',
                affected_headcount              INT          NOT NULL DEFAULT 0,
                affected_annual_turnover_eur    DECIMAL(15,2) NULL DEFAULT NULL,
                ict_dependency_description      LONGTEXT     NOT NULL,
                last_reported_at                DATETIME     NULL DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                next_due_at                     DATETIME     NOT NULL          COMMENT '(DC2Type:datetime_immutable)',
                portal_confirmation_number      VARCHAR(80)  NULL DEFAULT NULL,
                created_at                      DATETIME     NOT NULL          COMMENT '(DC2Type:datetime_immutable)',
                updated_at                      DATETIME     NULL DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE KEY uniq_nis2_profile_tenant (tenant_id),
                INDEX idx_nis2_profile_next_due_at (next_due_at),
                CONSTRAINT fk_nis2_profile_tenant
                    FOREIGN KEY (tenant_id)
                    REFERENCES tenant (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_nis2_profile_incident_contact
                    FOREIGN KEY (incident_reporting_contact_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_nis2_profile_security_contact
                    FOREIGN KEY (security_responsible_contact_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_nis2_profile_backup_contact
                    FOREIGN KEY (backup_security_contact_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS nis2_registration_profile');
    }
}
