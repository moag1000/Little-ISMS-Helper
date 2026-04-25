<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * VUL-01: Incident ↔ Vulnerability ManyToMany join table.
 * Enables linking incidents to the vulnerabilities that were exploited or related
 * to them (ISO 27001 A.8.8, NIS2 Art. 21.2.d, Risk-Incident integration).
 */
final class Version20260418120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'VUL-01: incident_vulnerability join table (Incident ↔ Vulnerability)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS incident_vulnerability (
            incident_id INT NOT NULL,
            vulnerability_id INT NOT NULL,
            INDEX IDX_IV_INCIDENT (incident_id),
            INDEX IDX_IV_VULN (vulnerability_id),
            PRIMARY KEY (incident_id, vulnerability_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Only add FKs if they don\'t already exist
        $this->addSql("SET @fk_iv_incident_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='incident_vulnerability' AND CONSTRAINT_NAME='FK_IV_INCIDENT')");
        $this->addSql("SET @sql := IF(@fk_iv_incident_exists = 0, 'ALTER TABLE incident_vulnerability ADD CONSTRAINT FK_IV_INCIDENT FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @fk_iv_vuln_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='incident_vulnerability' AND CONSTRAINT_NAME='FK_IV_VULN')");
        $this->addSql("SET @sql := IF(@fk_iv_vuln_exists = 0, 'ALTER TABLE incident_vulnerability ADD CONSTRAINT FK_IV_VULN FOREIGN KEY (vulnerability_id) REFERENCES vulnerabilities (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident_vulnerability DROP FOREIGN KEY FK_IV_INCIDENT');
        $this->addSql('ALTER TABLE incident_vulnerability DROP FOREIGN KEY FK_IV_VULN');
        $this->addSql('DROP TABLE incident_vulnerability');
    }
}
