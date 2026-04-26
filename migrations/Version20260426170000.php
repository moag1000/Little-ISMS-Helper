<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Verlinkung DPIA <-> Asset (insbesondere AI-Agents).
 *
 * Erfüllt:
 * - DSGVO Art. 35 (DPIA-Verknüpfung mit verarbeitendem System)
 * - EU AI Act Art. 9 (Risikomanagement für Hochrisiko-AI-Systeme)
 * - MRIS v1.5 MHC-13 (AI-Agent-Governance, Peddi 2026, CC BY 4.0)
 *
 * Spalte ist nullable, ON DELETE SET NULL, damit beim Löschen eines Assets
 * die DPIA-Historie für die Rechenschaftspflicht (Art. 5(2) DSGVO) erhalten
 * bleibt.
 */
final class Version20260426170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DPIA: nullable related_asset_id ManyToOne -> asset (AI Act Art. 9 + DSGVO Art. 35)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD related_asset_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_DPIA_RELATED_ASSET FOREIGN KEY (related_asset_id) REFERENCES asset (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DPIA_RELATED_ASSET ON data_protection_impact_assessment (related_asset_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_DPIA_RELATED_ASSET');
        $this->addSql('DROP INDEX IDX_DPIA_RELATED_ASSET ON data_protection_impact_assessment');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP related_asset_id');
    }
}
