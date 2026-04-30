<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430182000_ppa_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PrototypeProtectionAssessment: tri-state Person slot for assessor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prototype_protection_assessment ADD assessor_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prototype_protection_assessment ADD CONSTRAINT fk_ppa_assessor_person FOREIGN KEY (assessor_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE ppa_assessor_deputies (prototype_protection_assessment_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (prototype_protection_assessment_id, person_id))');
        $this->addSql('ALTER TABLE ppa_assessor_deputies ADD CONSTRAINT fk_ppa_ad_ppa FOREIGN KEY (prototype_protection_assessment_id) REFERENCES prototype_protection_assessment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ppa_assessor_deputies ADD CONSTRAINT fk_ppa_ad_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ppa_assessor_deputies');
        $this->addSql('ALTER TABLE prototype_protection_assessment DROP FOREIGN KEY fk_ppa_assessor_person');
        $this->addSql('ALTER TABLE prototype_protection_assessment DROP COLUMN assessor_person_id');
    }
}
