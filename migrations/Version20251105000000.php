<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create all ISMS core tables for ISO 27001 management';
    }

    public function up(Schema $schema): void
    {
        // Asset table
        $this->addSql('CREATE TABLE asset (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            asset_type VARCHAR(100) NOT NULL,
            owner VARCHAR(100) NOT NULL,
            location VARCHAR(100) DEFAULT NULL,
            confidentiality_value INT NOT NULL,
            integrity_value INT NOT NULL,
            availability_value INT NOT NULL,
            status VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Risk table
        $this->addSql('CREATE TABLE risk (
            id INT AUTO_INCREMENT NOT NULL,
            asset_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            threat LONGTEXT DEFAULT NULL,
            vulnerability LONGTEXT DEFAULT NULL,
            probability INT NOT NULL,
            impact INT NOT NULL,
            residual_probability INT NOT NULL,
            residual_impact INT NOT NULL,
            treatment_strategy VARCHAR(50) NOT NULL,
            treatment_description LONGTEXT DEFAULT NULL,
            risk_owner VARCHAR(100) DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            review_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX IDX_7906D5415DA1941 (asset_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Control table
        $this->addSql('CREATE TABLE control (
            id INT AUTO_INCREMENT NOT NULL,
            control_id VARCHAR(20) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            category VARCHAR(100) NOT NULL,
            applicable TINYINT(1) NOT NULL,
            justification LONGTEXT DEFAULT NULL,
            implementation_notes LONGTEXT DEFAULT NULL,
            implementation_status VARCHAR(50) NOT NULL,
            implementation_percentage INT DEFAULT NULL,
            responsible_person VARCHAR(100) DEFAULT NULL,
            target_date DATE DEFAULT NULL,
            last_review_date DATE DEFAULT NULL,
            next_review_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Control-Risk many-to-many
        $this->addSql('CREATE TABLE control_risk (
            control_id INT NOT NULL,
            risk_id INT NOT NULL,
            INDEX IDX_CONTROL (control_id),
            INDEX IDX_RISK (risk_id),
            PRIMARY KEY(control_id, risk_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Incident table
        $this->addSql('CREATE TABLE incident (
            id INT AUTO_INCREMENT NOT NULL,
            incident_number VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            category VARCHAR(100) NOT NULL,
            severity VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL,
            detected_at DATETIME NOT NULL,
            occurred_at DATETIME DEFAULT NULL,
            reported_by VARCHAR(100) NOT NULL,
            assigned_to VARCHAR(100) DEFAULT NULL,
            immediate_actions LONGTEXT DEFAULT NULL,
            root_cause LONGTEXT DEFAULT NULL,
            corrective_actions LONGTEXT DEFAULT NULL,
            preventive_actions LONGTEXT DEFAULT NULL,
            lessons_learned LONGTEXT DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            data_breach_occurred TINYINT(1) NOT NULL,
            notification_required TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Incident-Control many-to-many
        $this->addSql('CREATE TABLE incident_control (
            incident_id INT NOT NULL,
            control_id INT NOT NULL,
            INDEX IDX_INCIDENT (incident_id),
            INDEX IDX_CONTROL2 (control_id),
            PRIMARY KEY(incident_id, control_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Internal Audit table
        $this->addSql('CREATE TABLE internal_audit (
            id INT AUTO_INCREMENT NOT NULL,
            audit_number VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            scope LONGTEXT DEFAULT NULL,
            objectives LONGTEXT DEFAULT NULL,
            planned_date DATE NOT NULL,
            actual_date DATE DEFAULT NULL,
            lead_auditor VARCHAR(100) NOT NULL,
            audit_team LONGTEXT DEFAULT NULL,
            audited_departments LONGTEXT DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            findings LONGTEXT DEFAULT NULL,
            non_conformities LONGTEXT DEFAULT NULL,
            observations LONGTEXT DEFAULT NULL,
            recommendations LONGTEXT DEFAULT NULL,
            conclusion LONGTEXT DEFAULT NULL,
            report_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Management Review table
        $this->addSql('CREATE TABLE management_review (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            review_date DATE NOT NULL,
            participants LONGTEXT DEFAULT NULL,
            changes_relevant_to_isms LONGTEXT DEFAULT NULL,
            feedback_from_interested_parties LONGTEXT DEFAULT NULL,
            audit_results LONGTEXT DEFAULT NULL,
            performance_evaluation LONGTEXT DEFAULT NULL,
            non_conformities_status LONGTEXT DEFAULT NULL,
            corrective_actions_status LONGTEXT DEFAULT NULL,
            previous_review_actions LONGTEXT DEFAULT NULL,
            opportunities_for_improvement LONGTEXT DEFAULT NULL,
            resource_needs LONGTEXT DEFAULT NULL,
            decisions LONGTEXT DEFAULT NULL,
            action_items LONGTEXT DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Training table
        $this->addSql('CREATE TABLE training (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            training_type VARCHAR(100) NOT NULL,
            scheduled_date DATE NOT NULL,
            duration_minutes INT DEFAULT NULL,
            trainer VARCHAR(100) NOT NULL,
            target_audience LONGTEXT DEFAULT NULL,
            participants LONGTEXT DEFAULT NULL,
            attendee_count INT DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            materials LONGTEXT DEFAULT NULL,
            feedback LONGTEXT DEFAULT NULL,
            completion_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ISMS Context table
        $this->addSql('CREATE TABLE ismscontext (
            id INT AUTO_INCREMENT NOT NULL,
            organization_name VARCHAR(255) NOT NULL,
            isms_scope LONGTEXT DEFAULT NULL,
            scope_exclusions LONGTEXT DEFAULT NULL,
            external_issues LONGTEXT DEFAULT NULL,
            internal_issues LONGTEXT DEFAULT NULL,
            interested_parties LONGTEXT DEFAULT NULL,
            interested_parties_requirements LONGTEXT DEFAULT NULL,
            legal_requirements LONGTEXT DEFAULT NULL,
            regulatory_requirements LONGTEXT DEFAULT NULL,
            contractual_obligations LONGTEXT DEFAULT NULL,
            isms_policy LONGTEXT DEFAULT NULL,
            roles_and_responsibilities LONGTEXT DEFAULT NULL,
            last_review_date DATE DEFAULT NULL,
            next_review_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ISMS Objective table
        $this->addSql('CREATE TABLE ismsobjective (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            category VARCHAR(100) NOT NULL,
            measurable_indicators LONGTEXT DEFAULT NULL,
            target_value NUMERIC(10, 2) DEFAULT NULL,
            current_value NUMERIC(10, 2) DEFAULT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            responsible_person VARCHAR(100) NOT NULL,
            target_date DATE NOT NULL,
            status VARCHAR(50) NOT NULL,
            progress_notes LONGTEXT DEFAULT NULL,
            achieved_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign keys
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_ASSET FOREIGN KEY (asset_id) REFERENCES asset (id)');
        $this->addSql('ALTER TABLE control_risk ADD CONSTRAINT FK_CR_CONTROL FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE control_risk ADD CONSTRAINT FK_CR_RISK FOREIGN KEY (risk_id) REFERENCES risk (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_control ADD CONSTRAINT FK_IC_INCIDENT FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_control ADD CONSTRAINT FK_IC_CONTROL FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS incident_control');
        $this->addSql('DROP TABLE IF EXISTS control_risk');
        $this->addSql('DROP TABLE IF EXISTS ismsobjective');
        $this->addSql('DROP TABLE IF EXISTS ismscontext');
        $this->addSql('DROP TABLE IF EXISTS training');
        $this->addSql('DROP TABLE IF EXISTS management_review');
        $this->addSql('DROP TABLE IF EXISTS internal_audit');
        $this->addSql('DROP TABLE IF EXISTS incident');
        $this->addSql('DROP TABLE IF EXISTS control');
        $this->addSql('DROP TABLE IF EXISTS risk');
        $this->addSql('DROP TABLE IF EXISTS asset');
    }
}
