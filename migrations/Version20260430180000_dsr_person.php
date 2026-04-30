<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430180000_dsr_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DataSubjectRequest: tri-state Person slot for assignedTo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_subject_request ADD assigned_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_subject_request ADD CONSTRAINT fk_dsr_assigned_person FOREIGN KEY (assigned_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE dsr_assigned_deputies (data_subject_request_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (data_subject_request_id, person_id))');
        $this->addSql('ALTER TABLE dsr_assigned_deputies ADD CONSTRAINT fk_dsr_ad_dsr FOREIGN KEY (data_subject_request_id) REFERENCES data_subject_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dsr_assigned_deputies ADD CONSTRAINT fk_dsr_ad_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dsr_assigned_deputies');
        $this->addSql('ALTER TABLE data_subject_request DROP FOREIGN KEY fk_dsr_assigned_person');
        $this->addSql('ALTER TABLE data_subject_request DROP COLUMN assigned_person_id');
    }
}
