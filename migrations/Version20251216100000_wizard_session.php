<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 7E: Add WizardSession table for compliance wizard progress tracking
 */
final class Version20251216100000_wizard_session extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7E: Add wizard_session table for compliance wizard progress tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wizard_session (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            user_id INT NOT NULL,
            wizard_type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            current_step INT NOT NULL,
            total_steps INT NOT NULL,
            completed_categories JSON NOT NULL,
            assessment_results JSON NOT NULL,
            recommendations JSON NOT NULL,
            critical_gaps JSON NOT NULL,
            overall_score INT NOT NULL,
            started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_activity_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_wizard_session_tenant (tenant_id),
            INDEX idx_wizard_session_user (user_id),
            INDEX idx_wizard_session_wizard (wizard_type),
            INDEX idx_wizard_session_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE wizard_session ADD CONSTRAINT FK_wizard_session_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wizard_session ADD CONSTRAINT FK_wizard_session_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wizard_session DROP FOREIGN KEY FK_wizard_session_tenant');
        $this->addSql('ALTER TABLE wizard_session DROP FOREIGN KEY FK_wizard_session_user');
        $this->addSql('DROP TABLE wizard_session');
    }
}
