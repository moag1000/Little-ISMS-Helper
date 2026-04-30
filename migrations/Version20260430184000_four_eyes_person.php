<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430184000_four_eyes_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FourEyesApprovalRequest: tri-state Person slot for requestedApprover';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE four_eyes_approval_request ADD requested_approver_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE four_eyes_approval_request ADD CONSTRAINT fk_fer_approver_person FOREIGN KEY (requested_approver_person_id) REFERENCES person (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE four_eyes_approver_deputies (four_eyes_approval_request_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (four_eyes_approval_request_id, person_id))');
        $this->addSql('ALTER TABLE four_eyes_approver_deputies ADD CONSTRAINT fk_fer_ad_fer FOREIGN KEY (four_eyes_approval_request_id) REFERENCES four_eyes_approval_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE four_eyes_approver_deputies ADD CONSTRAINT fk_fer_ad_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE four_eyes_approver_deputies');
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY fk_fer_approver_person');
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP COLUMN requested_approver_person_id');
    }
}
