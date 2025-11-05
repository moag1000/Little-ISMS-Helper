<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entity relationships for maximum data reuse: Incident-Asset, Incident-Risk, Control-Asset, Training-Control, BusinessProcess-Risk';
    }

    public function up(Schema $schema): void
    {
        // Create incident_asset many-to-many table (already referenced in earlier migration, but completing here)
        $this->addSql('CREATE TABLE IF NOT EXISTS incident_asset (
            incident_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_INCIDENT (incident_id),
            INDEX IDX_ASSET (asset_id),
            PRIMARY KEY(incident_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE incident_asset
            ADD CONSTRAINT FK_IA_INCIDENT FOREIGN KEY (incident_id)
            REFERENCES incident (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE incident_asset
            ADD CONSTRAINT FK_IA_ASSET FOREIGN KEY (asset_id)
            REFERENCES asset (id) ON DELETE CASCADE');

        // Create incident_risk many-to-many table
        $this->addSql('CREATE TABLE IF NOT EXISTS incident_risk (
            incident_id INT NOT NULL,
            risk_id INT NOT NULL,
            INDEX IDX_INCIDENT (incident_id),
            INDEX IDX_RISK (risk_id),
            PRIMARY KEY(incident_id, risk_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE incident_risk
            ADD CONSTRAINT FK_IR_INCIDENT FOREIGN KEY (incident_id)
            REFERENCES incident (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE incident_risk
            ADD CONSTRAINT FK_IR_RISK FOREIGN KEY (risk_id)
            REFERENCES risk (id) ON DELETE CASCADE');

        // Create control_asset many-to-many table
        $this->addSql('CREATE TABLE IF NOT EXISTS control_asset (
            control_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_CONTROL (control_id),
            INDEX IDX_ASSET (asset_id),
            PRIMARY KEY(control_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE control_asset
            ADD CONSTRAINT FK_CA_CONTROL FOREIGN KEY (control_id)
            REFERENCES control (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE control_asset
            ADD CONSTRAINT FK_CA_ASSET FOREIGN KEY (asset_id)
            REFERENCES asset (id) ON DELETE CASCADE');

        // Create training_control many-to-many table
        $this->addSql('CREATE TABLE IF NOT EXISTS training_control (
            training_id INT NOT NULL,
            control_id INT NOT NULL,
            INDEX IDX_TRAINING (training_id),
            INDEX IDX_CONTROL (control_id),
            PRIMARY KEY(training_id, control_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE training_control
            ADD CONSTRAINT FK_TC_TRAINING FOREIGN KEY (training_id)
            REFERENCES training (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE training_control
            ADD CONSTRAINT FK_TC_CONTROL FOREIGN KEY (control_id)
            REFERENCES control (id) ON DELETE CASCADE');

        // Create business_process_risk many-to-many table
        $this->addSql('CREATE TABLE IF NOT EXISTS business_process_risk (
            business_process_id INT NOT NULL,
            risk_id INT NOT NULL,
            INDEX IDX_BUSINESS_PROCESS (business_process_id),
            INDEX IDX_RISK (risk_id),
            PRIMARY KEY(business_process_id, risk_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE business_process_risk
            ADD CONSTRAINT FK_BPR_BUSINESS_PROCESS FOREIGN KEY (business_process_id)
            REFERENCES business_process (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE business_process_risk
            ADD CONSTRAINT FK_BPR_RISK FOREIGN KEY (risk_id)
            REFERENCES risk (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS business_process_risk');
        $this->addSql('DROP TABLE IF EXISTS training_control');
        $this->addSql('DROP TABLE IF EXISTS control_asset');
        $this->addSql('DROP TABLE IF EXISTS incident_risk');
        $this->addSql('DROP TABLE IF EXISTS incident_asset');
    }
}
