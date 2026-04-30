<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430181000_management_review_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ManagementReview: tri-state Person slot for reviewedBy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE management_review ADD reviewed_by_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT fk_mr_reviewed_by_person FOREIGN KEY (reviewed_by_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE management_review_reviewed_by_deputies (management_review_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (management_review_id, person_id))');
        $this->addSql('ALTER TABLE management_review_reviewed_by_deputies ADD CONSTRAINT fk_mr_rbd_review FOREIGN KEY (management_review_id) REFERENCES management_review (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE management_review_reviewed_by_deputies ADD CONSTRAINT fk_mr_rbd_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE management_review_reviewed_by_deputies');
        $this->addSql('ALTER TABLE management_review DROP FOREIGN KEY fk_mr_reviewed_by_person');
        $this->addSql('ALTER TABLE management_review DROP COLUMN reviewed_by_person_id');
    }
}
