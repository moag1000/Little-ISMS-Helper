<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6F-B3: Create RiskTreatmentPlan entity for ISO 27005 compliance
 *
 * Features:
 * - Risk treatment plan tracking with timeline
 * - Status: planned, in_progress, completed, cancelled, on_hold
 * - Priority: low, medium, high, critical
 * - Budget tracking
 * - Responsible person assignment
 * - Many-to-Many relationship with Controls
 * - Completion percentage tracking
 */
final class Version20251110162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6F-B3: Create RiskTreatmentPlan entity for risk treatment tracking';
    }

    public function up(Schema $schema): void
    {
        // Create risk_treatment_plan table
        $this->addSql('CREATE TABLE risk_treatment_plan (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            risk_id INT NOT NULL,
            responsible_person_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            status VARCHAR(50) NOT NULL,
            priority VARCHAR(20) NOT NULL,
            start_date DATE DEFAULT NULL,
            target_completion_date DATE NOT NULL,
            actual_completion_date DATE DEFAULT NULL,
            budget NUMERIC(15, 2) DEFAULT NULL,
            implementation_notes LONGTEXT DEFAULT NULL,
            completion_percentage INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_treatment_plan_status (status),
            INDEX idx_treatment_plan_priority (priority),
            INDEX idx_treatment_plan_target (target_completion_date),
            INDEX idx_treatment_plan_tenant (tenant_id),
            INDEX IDX_DDFE15679033212A (tenant_id),
            INDEX IDX_DDFE1567BE3DB2B7 (risk_id),
            INDEX IDX_DDFE1567EDD5A5D1 (responsible_person_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create join table for Many-to-Many relationship with Control
        $this->addSql('CREATE TABLE risk_treatment_plan_control (
            risk_treatment_plan_id INT NOT NULL,
            control_id INT NOT NULL,
            INDEX IDX_8C4A2F8BDBF68B10 (risk_treatment_plan_id),
            INDEX IDX_8C4A2F8B2E70E7FD (control_id),
            PRIMARY KEY(risk_treatment_plan_id, control_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE risk_treatment_plan ADD CONSTRAINT FK_DDFE15679033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE risk_treatment_plan ADD CONSTRAINT FK_DDFE1567BE3DB2B7 FOREIGN KEY (risk_id) REFERENCES risk (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk_treatment_plan ADD CONSTRAINT FK_DDFE1567EDD5A5D1 FOREIGN KEY (responsible_person_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE risk_treatment_plan_control ADD CONSTRAINT FK_8C4A2F8BDBF68B10 FOREIGN KEY (risk_treatment_plan_id) REFERENCES risk_treatment_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk_treatment_plan_control ADD CONSTRAINT FK_8C4A2F8B2E70E7FD FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_treatment_plan DROP FOREIGN KEY FK_DDFE15679033212A');
        $this->addSql('ALTER TABLE risk_treatment_plan DROP FOREIGN KEY FK_DDFE1567BE3DB2B7');
        $this->addSql('ALTER TABLE risk_treatment_plan DROP FOREIGN KEY FK_DDFE1567EDD5A5D1');
        $this->addSql('ALTER TABLE risk_treatment_plan_control DROP FOREIGN KEY FK_8C4A2F8BDBF68B10');
        $this->addSql('ALTER TABLE risk_treatment_plan_control DROP FOREIGN KEY FK_8C4A2F8B2E70E7FD');
        $this->addSql('DROP TABLE risk_treatment_plan_control');
        $this->addSql('DROP TABLE risk_treatment_plan');
    }
}
