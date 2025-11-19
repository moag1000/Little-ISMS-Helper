<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119110304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRITICAL-05 Phase 1: Add Incident ↔ BusinessProcess relationship for BCM impact analysis';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE incident_business_process (incident_id INT NOT NULL, business_process_id INT NOT NULL, INDEX IDX_BF69C9C559E53FB9 (incident_id), INDEX IDX_BF69C9C5BE61FDDF (business_process_id), PRIMARY KEY (incident_id, business_process_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE incident_business_process ADD CONSTRAINT FK_BF69C9C559E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_business_process ADD CONSTRAINT FK_BF69C9C5BE61FDDF FOREIGN KEY (business_process_id) REFERENCES business_process (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE incident_business_process DROP FOREIGN KEY FK_BF69C9C559E53FB9');
        $this->addSql('ALTER TABLE incident_business_process DROP FOREIGN KEY FK_BF69C9C5BE61FDDF');
        $this->addSql('DROP TABLE incident_business_process');
    }
}
