<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430183000_threat_intelligence_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ThreatIntelligence: tri-state Person slot for assignedTo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE threat_intelligence ADD assigned_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE threat_intelligence ADD CONSTRAINT fk_ti_assigned_person FOREIGN KEY (assigned_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE threat_intelligence_assigned_deputies (threat_intelligence_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (threat_intelligence_id, person_id))');
        $this->addSql('ALTER TABLE threat_intelligence_assigned_deputies ADD CONSTRAINT fk_ti_ad_ti FOREIGN KEY (threat_intelligence_id) REFERENCES threat_intelligence (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE threat_intelligence_assigned_deputies ADD CONSTRAINT fk_ti_ad_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE threat_intelligence_assigned_deputies');
        $this->addSql('ALTER TABLE threat_intelligence DROP FOREIGN KEY fk_ti_assigned_person');
        $this->addSql('ALTER TABLE threat_intelligence DROP COLUMN assigned_person_id');
    }
}
