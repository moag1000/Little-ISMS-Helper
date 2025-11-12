<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6L-B: Add system_settings table for web-based configuration
 */
final class Version20251112000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system_settings table for Phase 6L-B (System Configuration UI)';
    }

    public function up(Schema $schema): void
    {
        // Create system_settings table
        $this->addSql('CREATE TABLE system_settings (
            id INT AUTO_INCREMENT NOT NULL,
            category VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            value JSON NOT NULL,
            encrypted_value LONGTEXT DEFAULT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_by VARCHAR(180) DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_CATEGORY_KEY (category, setting_key)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE system_settings');
    }
}
