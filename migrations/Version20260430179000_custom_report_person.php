<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430179000_custom_report_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CustomReport: tri-state Person slot for owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_report ADD owner_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE custom_report ADD CONSTRAINT fk_cr_owner_person FOREIGN KEY (owner_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE custom_report_owner_deputies (custom_report_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (custom_report_id, person_id))');
        $this->addSql('ALTER TABLE custom_report_owner_deputies ADD CONSTRAINT fk_cr_od_report FOREIGN KEY (custom_report_id) REFERENCES custom_report (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_report_owner_deputies ADD CONSTRAINT fk_cr_od_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE custom_report_owner_deputies');
        $this->addSql('ALTER TABLE custom_report DROP FOREIGN KEY fk_cr_owner_person');
        $this->addSql('ALTER TABLE custom_report DROP COLUMN owner_person_id');
    }
}
