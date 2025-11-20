<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251120171530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE compliance_requirement_fulfillment (id INT AUTO_INCREMENT NOT NULL, applicable TINYINT(1) NOT NULL, applicability_justification LONGTEXT DEFAULT NULL, fulfillment_percentage INT NOT NULL, fulfillment_notes LONGTEXT DEFAULT NULL, evidence_description LONGTEXT DEFAULT NULL, last_review_date DATE DEFAULT NULL, next_review_date DATE DEFAULT NULL, status VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, tenant_id INT NOT NULL, requirement_id INT NOT NULL, responsible_person_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_308AE242EF64F467 (responsible_person_id), INDEX IDX_308AE242E562D849 (last_updated_by_id), INDEX idx_tenant (tenant_id), INDEX idx_requirement (requirement_id), INDEX idx_fulfillment_percentage (fulfillment_percentage), UNIQUE INDEX unique_tenant_requirement (tenant_id, requirement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT FK_308AE2429033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT FK_308AE2427B576F77 FOREIGN KEY (requirement_id) REFERENCES compliance_requirement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT FK_308AE242EF64F467 FOREIGN KEY (responsible_person_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT FK_308AE242E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY `FK_57D957D32BEC70E`');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY `FK_57D957D492951C7`');
        $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT FK_57D957D32BEC70E FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT FK_57D957D492951C7 FOREIGN KEY (compliance_requirement_id) REFERENCES compliance_requirement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY `FK_CBC21BBA4F8A983C`');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBA4F8A983C FOREIGN KEY (contact_person_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY `FK_7906D5415DA1941`');
        $this->addSql('ALTER TABLE risk ADD person_id INT DEFAULT NULL, ADD location_id INT DEFAULT NULL, ADD supplier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5415DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D54164D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5412ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_7906D541217BBB47 ON risk (person_id)');
        $this->addSql('CREATE INDEX IDX_7906D54164D218E ON risk (location_id)');
        $this->addSql('CREATE INDEX IDX_7906D5412ADD6D8C ON risk (supplier_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY FK_308AE2429033212A');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY FK_308AE2427B576F77');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY FK_308AE242EF64F467');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY FK_308AE242E562D849');
        $this->addSql('DROP TABLE compliance_requirement_fulfillment');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D32BEC70E');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D492951C7');
        $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT `FK_57D957D32BEC70E` FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBA4F8A983C');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT `FK_CBC21BBA4F8A983C` FOREIGN KEY (contact_person_id) REFERENCES person (id)');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5415DA1941');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541217BBB47');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D54164D218E');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5412ADD6D8C');
        $this->addSql('DROP INDEX IDX_7906D541217BBB47 ON risk');
        $this->addSql('DROP INDEX IDX_7906D54164D218E ON risk');
        $this->addSql('DROP INDEX IDX_7906D5412ADD6D8C ON risk');
        $this->addSql('ALTER TABLE risk DROP person_id, DROP location_id, DROP supplier_id');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT `FK_7906D5415DA1941` FOREIGN KEY (asset_id) REFERENCES asset (id)');
    }
}
