<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create business_process table for BCM/BIA and link to assets';
    }

    public function up(Schema $schema): void
    {
        // Business Process table
        $this->addSql('CREATE TABLE business_process (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            process_owner VARCHAR(100) DEFAULT NULL,
            criticality VARCHAR(50) NOT NULL,
            rto INT NOT NULL COMMENT "Recovery Time Objective in hours",
            rpo INT NOT NULL COMMENT "Recovery Point Objective in hours",
            mtpd INT NOT NULL COMMENT "Maximum Tolerable Period of Disruption in hours",
            financial_impact INT NOT NULL COMMENT "1-10 scale",
            reputational_impact INT NOT NULL COMMENT "1-10 scale",
            regulatory_impact INT NOT NULL COMMENT "1-10 scale",
            operational_impact INT NOT NULL COMMENT "1-10 scale",
            dependencies LONGTEXT DEFAULT NULL,
            recovery_strategy LONGTEXT DEFAULT NULL,
            minimum_resources LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Business Process - Asset many-to-many relationship
        $this->addSql('CREATE TABLE business_process_asset (
            business_process_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_BP (business_process_id),
            INDEX IDX_ASSET (asset_id),
            PRIMARY KEY(business_process_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign keys
        $this->addSql('ALTER TABLE business_process_asset
            ADD CONSTRAINT FK_BPA_BP FOREIGN KEY (business_process_id)
            REFERENCES business_process (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business_process_asset
            ADD CONSTRAINT FK_BPA_ASSET FOREIGN KEY (asset_id)
            REFERENCES asset (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS business_process_asset');
        $this->addSql('DROP TABLE IF EXISTS business_process');
    }
}
