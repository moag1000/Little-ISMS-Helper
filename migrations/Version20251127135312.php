<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251127135312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make DataBreach.incident optional and add detected_at field for standalone data breaches';
    }

    public function up(Schema $schema): void
    {
        // Make incident_id nullable and change ON DELETE to SET NULL
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY `FK_5F46FA6C59E53FB9`');
        $this->addSql('ALTER TABLE data_breach CHANGE incident_id incident_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6C59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE SET NULL');

        // Add detected_at column - initially nullable to handle existing data
        $this->addSql('ALTER TABLE data_breach ADD detected_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        // Populate detected_at from linked incident for existing records
        $this->addSql('UPDATE data_breach db
                       INNER JOIN incident i ON db.incident_id = i.id
                       SET db.detected_at = COALESCE(i.detected_at, db.created_at)');

        // Set detected_at to created_at for any records without linked incident
        $this->addSql('UPDATE data_breach SET detected_at = created_at WHERE detected_at IS NULL');

        // Now make detected_at NOT NULL
        $this->addSql('ALTER TABLE data_breach CHANGE detected_at detected_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6C59E53FB9');
        $this->addSql('ALTER TABLE data_breach DROP detected_at, CHANGE incident_id incident_id INT NOT NULL');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT `FK_5F46FA6C59E53FB9` FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
    }
}
