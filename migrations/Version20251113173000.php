<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Create user_sessions table for session management
 */
final class Version20251113173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_sessions table for tracking active user sessions (NIS2 compliance)';
    }

    public function up(Schema $schema): void
    {
        // Create user_sessions table
        $this->addSql('CREATE TABLE user_sessions (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent LONGTEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_activity_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            ended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            end_reason VARCHAR(20) DEFAULT NULL,
            terminated_by VARCHAR(180) DEFAULT NULL,
            UNIQUE INDEX UNIQ_31BBDC269AB44FE0 (session_id),
            INDEX idx_session_user (user_id),
            INDEX idx_session_id (session_id),
            INDEX idx_session_active (is_active),
            INDEX idx_session_activity (last_activity_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE user_sessions ADD CONSTRAINT FK_31BBDC26A76ED395
            FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE user_sessions DROP FOREIGN KEY FK_31BBDC26A76ED395');

        // Drop table
        $this->addSql('DROP TABLE user_sessions');
    }
}
