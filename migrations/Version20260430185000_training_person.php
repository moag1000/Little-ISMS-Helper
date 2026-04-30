<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430185000_training_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training: tri-state Person slot for trainerUser';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training ADD trainer_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE training ADD CONSTRAINT fk_training_trainer_person FOREIGN KEY (trainer_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE training_trainer_deputies (training_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (training_id, person_id))');
        $this->addSql('ALTER TABLE training_trainer_deputies ADD CONSTRAINT fk_training_td_training FOREIGN KEY (training_id) REFERENCES training (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_trainer_deputies ADD CONSTRAINT fk_training_td_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_trainer_deputies');
        $this->addSql('ALTER TABLE training DROP FOREIGN KEY fk_training_trainer_person');
        $this->addSql('ALTER TABLE training DROP COLUMN trainer_person_id');
    }
}
