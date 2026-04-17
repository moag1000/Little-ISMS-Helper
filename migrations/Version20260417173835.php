<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bugfix migration: Fix dangerous CASCADE deletes and Document nullable uploaded_by
 *
 * - Risk: Change asset/person/location/supplier FK from CASCADE to SET NULL
 * - ComplianceRequirement: Change parent_requirement FK from CASCADE to SET NULL
 * - Document: Make uploaded_by_id nullable with SET NULL on delete
 */
final class Version20260417173835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix CASCADE→SET NULL on Risk subjects, ComplianceRequirement parent, and Document uploaded_by';
    }

    public function up(Schema $schema): void
    {
        // Risk: Change all 4 subject FKs from CASCADE to SET NULL
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5415DA1941');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541217BBB47');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D54164D218E');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5412ADD6D8C');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5415DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D54164D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5412ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE SET NULL');

        // ComplianceRequirement: Change parent FK from CASCADE to SET NULL
        $this->addSql('ALTER TABLE compliance_requirement DROP FOREIGN KEY FK_D115DC52658A1B7C');
        $this->addSql('ALTER TABLE compliance_requirement ADD CONSTRAINT FK_D115DC52658A1B7C FOREIGN KEY (parent_requirement_id) REFERENCES compliance_requirement (id) ON DELETE SET NULL');

        // Document: Make uploaded_by nullable + SET NULL on delete
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE document CHANGE uploaded_by_id uploaded_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Risk: Revert to CASCADE
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5415DA1941');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541217BBB47');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D54164D218E');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5412ADD6D8C');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5415DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D54164D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5412ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');

        // ComplianceRequirement: Revert to CASCADE
        $this->addSql('ALTER TABLE compliance_requirement DROP FOREIGN KEY FK_D115DC52658A1B7C');
        $this->addSql('ALTER TABLE compliance_requirement ADD CONSTRAINT FK_D115DC52658A1B7C FOREIGN KEY (parent_requirement_id) REFERENCES compliance_requirement (id) ON DELETE CASCADE');

        // Document: Revert to NOT NULL without ON DELETE
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE document CHANGE uploaded_by_id uploaded_by_id INT NOT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id)');
    }
}
