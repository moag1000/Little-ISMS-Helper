<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 7C: Custom Report Builder - Creates custom_report table
 */
final class Version20251218100000_custom_report extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7C: Creates custom_report table for Custom Report Builder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE custom_report (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            category VARCHAR(50) NOT NULL,
            layout VARCHAR(20) NOT NULL,
            widgets JSON NOT NULL,
            filters JSON NOT NULL,
            styles JSON NOT NULL,
            is_shared TINYINT(1) NOT NULL DEFAULT 0,
            is_template TINYINT(1) NOT NULL DEFAULT 0,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            usage_count INT DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            shared_with JSON NOT NULL,
            tenant_id INT NOT NULL,
            version INT DEFAULT NULL,
            parent_template_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            owner_id INT NOT NULL,
            INDEX idx_custom_report_tenant (tenant_id),
            INDEX idx_custom_report_owner (owner_id),
            INDEX idx_custom_report_shared (is_shared),
            INDEX idx_custom_report_template (is_template),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE custom_report ADD CONSTRAINT FK_F082A3807E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_report DROP FOREIGN KEY FK_F082A3807E3C61F9');
        $this->addSql('DROP TABLE custom_report');
    }
}
