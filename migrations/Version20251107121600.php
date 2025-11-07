<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251107121600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant_id relations to Asset, Risk, Incident, Control, and Document entities. Add status field to Document entity.';
    }

    public function up(Schema $schema): void
    {
        // Add tenant_id column to asset table
        $this->addSql('ALTER TABLE asset ADD COLUMN tenant_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asset_tenant ON asset (tenant_id)');
        $this->addSql('ALTER TABLE asset ADD CONSTRAINT FK_2AF5A5C9178D3548 FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Add tenant_id column to risk table
        $this->addSql('ALTER TABLE risk ADD COLUMN tenant_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_risk_tenant ON risk (tenant_id)');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541178D3548 FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Add tenant_id column to incident table
        $this->addSql('ALTER TABLE incident ADD COLUMN tenant_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_incident_tenant ON incident (tenant_id)');
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11A178D3548 FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Add tenant_id column to control table
        $this->addSql('ALTER TABLE control ADD COLUMN tenant_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_control_tenant ON control (tenant_id)');
        $this->addSql('ALTER TABLE control ADD CONSTRAINT FK_C42067F4178D3548 FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Add tenant_id column to document table
        $this->addSql('ALTER TABLE document ADD COLUMN tenant_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_tenant ON document (tenant_id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76178D3548 FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Add status column to document table
        $this->addSql('ALTER TABLE document ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT \'active\'');
    }

    public function down(Schema $schema): void
    {
        // Remove status column from document table
        $this->addSql('ALTER TABLE document DROP COLUMN status');

        // Remove tenant_id from document table
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76178D3548');
        $this->addSql('DROP INDEX idx_document_tenant ON document');
        $this->addSql('ALTER TABLE document DROP COLUMN tenant_id');

        // Remove tenant_id from control table
        $this->addSql('ALTER TABLE control DROP FOREIGN KEY FK_C42067F4178D3548');
        $this->addSql('DROP INDEX idx_control_tenant ON control');
        $this->addSql('ALTER TABLE control DROP COLUMN tenant_id');

        // Remove tenant_id from incident table
        $this->addSql('ALTER TABLE incident DROP FOREIGN KEY FK_3D03A11A178D3548');
        $this->addSql('DROP INDEX idx_incident_tenant ON incident');
        $this->addSql('ALTER TABLE incident DROP COLUMN tenant_id');

        // Remove tenant_id from risk table
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541178D3548');
        $this->addSql('DROP INDEX idx_risk_tenant ON risk');
        $this->addSql('ALTER TABLE risk DROP COLUMN tenant_id');

        // Remove tenant_id from asset table
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY FK_2AF5A5C9178D3548');
        $this->addSql('DROP INDEX idx_asset_tenant ON asset');
        $this->addSql('ALTER TABLE asset DROP COLUMN tenant_id');
    }
}
