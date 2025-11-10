<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6F-B2: Create RiskAppetite entity for ISO 27005 compliance
 *
 * Features:
 * - Global and category-specific risk appetite levels
 * - Maximum acceptable risk score (1-25 scale)
 * - Approval workflow (approvedBy, approvedAt)
 * - Active status management
 * - Multi-tenant support
 */
final class Version20251110161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6F-B2: Create RiskAppetite entity for risk appetite management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE risk_appetite (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            approved_by_id INT DEFAULT NULL,
            category VARCHAR(100) DEFAULT NULL,
            max_acceptable_risk INT NOT NULL,
            description LONGTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL,
            approved_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_risk_appetite_category (category),
            INDEX idx_risk_appetite_active (is_active),
            INDEX idx_risk_appetite_tenant (tenant_id),
            INDEX IDX_9A8F3EE79033212A (tenant_id),
            INDEX IDX_9A8F3EE72D234F6A (approved_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE risk_appetite ADD CONSTRAINT FK_9A8F3EE79033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE risk_appetite ADD CONSTRAINT FK_9A8F3EE72D234F6A FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_appetite DROP FOREIGN KEY FK_9A8F3EE79033212A');
        $this->addSql('ALTER TABLE risk_appetite DROP FOREIGN KEY FK_9A8F3EE72D234F6A');
        $this->addSql('DROP TABLE risk_appetite');
    }
}
