<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Person entity
 *
 * Creates centralized person management table for employees, contractors, visitors, etc.
 * Enables data reuse across PhysicalAccessLog and other entities.
 */
final class Version20251108000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create person table for centralized person management with links to User and Tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE person (
            id INT AUTO_INCREMENT NOT NULL,
            linked_user_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            full_name VARCHAR(255) NOT NULL,
            person_type VARCHAR(50) NOT NULL,
            badge_id VARCHAR(100) DEFAULT NULL,
            company VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            department VARCHAR(100) DEFAULT NULL,
            job_title VARCHAR(100) DEFAULT NULL,
            active TINYINT(1) NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            access_valid_from DATE DEFAULT NULL,
            access_valid_until DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_PERSON_BADGE (badge_id),
            INDEX idx_person_type (person_type),
            INDEX idx_person_badge (badge_id),
            INDEX idx_person_company (company),
            INDEX IDX_PERSON_LINKED_USER (linked_user_id),
            INDEX IDX_PERSON_TENANT (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE person
            ADD CONSTRAINT FK_PERSON_LINKED_USER FOREIGN KEY (linked_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE person
            ADD CONSTRAINT FK_PERSON_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person DROP FOREIGN KEY FK_PERSON_LINKED_USER');
        $this->addSql('ALTER TABLE person DROP FOREIGN KEY FK_PERSON_TENANT');
        $this->addSql('DROP TABLE person');
    }
}
