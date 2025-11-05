<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log table for comprehensive activity logging to ensure audit trail compliance';
    }

    public function up(Schema $schema): void
    {
        // Create audit_log table for revision-safe activity logging
        $this->addSql('CREATE TABLE audit_log (
            id INT AUTO_INCREMENT NOT NULL,
            entity_type VARCHAR(100) NOT NULL,
            entity_id INT DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            ip_address VARCHAR(100) DEFAULT NULL,
            old_values LONGTEXT DEFAULT NULL,
            new_values LONGTEXT DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            user_agent VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_user (user_name),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_log');
    }
}
