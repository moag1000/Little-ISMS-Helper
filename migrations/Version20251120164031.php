<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251120164031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY `FK_57D957D32BEC70E`');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY `FK_57D957D492951C7`');
        $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT FK_57D957D32BEC70E FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment CHANGE applicable applicable TINYINT(1) NOT NULL, CHANGE fulfillment_percentage fulfillment_percentage INT NOT NULL, CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY `FK_CBC21BBA4F8A983C`');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBA4F8A983C FOREIGN KEY (contact_person_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D492951C7');
        $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY FK_57D957D32BEC70E');
        $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT `FK_57D957D492951C7` FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment CHANGE applicable applicable TINYINT(1) DEFAULT 1 NOT NULL, CHANGE fulfillment_percentage fulfillment_percentage INT DEFAULT 0 NOT NULL, CHANGE status status VARCHAR(50) DEFAULT \'not_started\' NOT NULL');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBA4F8A983C');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT `FK_CBC21BBA4F8A983C` FOREIGN KEY (contact_person_id) REFERENCES person (id)');
    }
}
