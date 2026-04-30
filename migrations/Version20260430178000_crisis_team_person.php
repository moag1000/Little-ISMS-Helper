<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430178000_crisis_team_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CrisisTeam: tri-state Person slots for teamLeader + deputyLeader';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crisis_teams ADD team_leader_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE crisis_teams ADD deputy_leader_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE crisis_teams ADD CONSTRAINT fk_ct_team_leader_person FOREIGN KEY (team_leader_person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crisis_teams ADD CONSTRAINT fk_ct_deputy_leader_person FOREIGN KEY (deputy_leader_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE crisis_team_leader_deputies (crisis_team_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (crisis_team_id, person_id))');
        $this->addSql('ALTER TABLE crisis_team_leader_deputies ADD CONSTRAINT fk_ct_ld_team FOREIGN KEY (crisis_team_id) REFERENCES crisis_teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crisis_team_leader_deputies ADD CONSTRAINT fk_ct_ld_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE crisis_team_deputy_leader_deputies (crisis_team_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (crisis_team_id, person_id))');
        $this->addSql('ALTER TABLE crisis_team_deputy_leader_deputies ADD CONSTRAINT fk_ct_dld_team FOREIGN KEY (crisis_team_id) REFERENCES crisis_teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crisis_team_deputy_leader_deputies ADD CONSTRAINT fk_ct_dld_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crisis_team_leader_deputies');
        $this->addSql('DROP TABLE crisis_team_deputy_leader_deputies');
        $this->addSql('ALTER TABLE crisis_teams DROP FOREIGN KEY fk_ct_team_leader_person');
        $this->addSql('ALTER TABLE crisis_teams DROP FOREIGN KEY fk_ct_deputy_leader_person');
        $this->addSql('ALTER TABLE crisis_teams DROP COLUMN team_leader_person_id');
        $this->addSql('ALTER TABLE crisis_teams DROP COLUMN deputy_leader_person_id');
    }
}
