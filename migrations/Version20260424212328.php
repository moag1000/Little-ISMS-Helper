<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260424212328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FairyAurora v4.0: Add alva_companion_enabled/size/position to users table (Phase 4.2)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement ADD CONSTRAINT FK_D115DC52658A1B7C FOREIGN KEY (parent_requirement_id) REFERENCES compliance_requirement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY `fk_feyes_requested_approver`');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5415DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D54164D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5412ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users ADD alva_companion_enabled TINYINT DEFAULT 1 NOT NULL, ADD alva_companion_size VARCHAR(8) DEFAULT \'md\' NOT NULL, ADD alva_companion_position VARCHAR(20) DEFAULT \'bottom-right\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_requirement DROP FOREIGN KEY FK_D115DC52658A1B7C');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5415DA1941');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541217BBB47');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D54164D218E');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5412ADD6D8C');
        $this->addSql('ALTER TABLE users DROP alva_companion_enabled, DROP alva_companion_size, DROP alva_companion_position');
    }
}
