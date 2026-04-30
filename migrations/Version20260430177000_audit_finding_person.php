<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430177000_audit_finding_person extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuditFinding: tri-state Person slots for assignedTo + reportedBy';
    }

    public function up(Schema $schema): void
    {
        // assignedPerson FK
        $this->addSql('ALTER TABLE audit_findings ADD assigned_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_findings ADD reported_by_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_findings ADD CONSTRAINT fk_af_assigned_person FOREIGN KEY (assigned_person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_findings ADD CONSTRAINT fk_af_reported_by_person FOREIGN KEY (reported_by_person_id) REFERENCES person (id) ON DELETE SET NULL');

        // deputies join tables
        $this->addSql('CREATE TABLE audit_finding_assigned_deputies (audit_finding_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (audit_finding_id, person_id))');
        $this->addSql('ALTER TABLE audit_finding_assigned_deputies ADD CONSTRAINT fk_af_ad_finding FOREIGN KEY (audit_finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_finding_assigned_deputies ADD CONSTRAINT fk_af_ad_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE audit_finding_reported_by_deputies (audit_finding_id INT NOT NULL, person_id INT NOT NULL, PRIMARY KEY (audit_finding_id, person_id))');
        $this->addSql('ALTER TABLE audit_finding_reported_by_deputies ADD CONSTRAINT fk_af_rbd_finding FOREIGN KEY (audit_finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_finding_reported_by_deputies ADD CONSTRAINT fk_af_rbd_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_finding_assigned_deputies');
        $this->addSql('DROP TABLE audit_finding_reported_by_deputies');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY fk_af_assigned_person');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY fk_af_reported_by_person');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN assigned_person_id');
        $this->addSql('ALTER TABLE audit_findings DROP COLUMN reported_by_person_id');
    }
}
