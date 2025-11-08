<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for ISO Compliance Improvements
 *
 * Adds the following tables:
 * - supplier: Vendor/supplier management (ISO 27001 A.15)
 * - interested_party: Stakeholder management (ISO 27001 4.2)
 * - business_continuity_plan: BC plan documentation (ISO 22301)
 * - bc_exercise: BC testing and exercises (ISO 22301 8.4)
 * - change_request: ISMS change management
 *
 * Adds fields to risk table:
 * - acceptance_approved_by, acceptance_approved_at
 * - acceptance_justification, formally_accepted
 */
final class Version20251108000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ISO compliance improvements: Supplier, InterestedParty, BC Plan, BC Exercise, Change Request entities + Risk acceptance approval';
    }

    public function up(Schema $schema): void
    {
        // Supplier table
        $this->addSql('CREATE TABLE supplier (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            contact_person VARCHAR(100) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            address LONGTEXT DEFAULT NULL,
            service_provided LONGTEXT NOT NULL,
            criticality VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL,
            security_score INT DEFAULT NULL,
            last_security_assessment DATE DEFAULT NULL,
            next_assessment_date DATE DEFAULT NULL,
            assessment_findings LONGTEXT DEFAULT NULL,
            non_conformities LONGTEXT DEFAULT NULL,
            contractual_slas JSON DEFAULT NULL,
            contract_start_date DATE DEFAULT NULL,
            contract_end_date DATE DEFAULT NULL,
            security_requirements LONGTEXT DEFAULT NULL,
            has_iso27001 TINYINT(1) NOT NULL,
            has_iso22301 TINYINT(1) NOT NULL,
            certifications LONGTEXT DEFAULT NULL,
            has_dpa TINYINT(1) NOT NULL,
            dpa_signed_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_supplier_criticality (criticality),
            INDEX idx_supplier_next_assessment (next_assessment_date),
            INDEX idx_supplier_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Join tables for Supplier
        $this->addSql('CREATE TABLE supplier_asset (
            supplier_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_SUPPLIER_ASSET_SUPPLIER (supplier_id),
            INDEX IDX_SUPPLIER_ASSET_ASSET (asset_id),
            PRIMARY KEY(supplier_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE supplier_risk (
            supplier_id INT NOT NULL,
            risk_id INT NOT NULL,
            INDEX IDX_SUPPLIER_RISK_SUPPLIER (supplier_id),
            INDEX IDX_SUPPLIER_RISK_RISK (risk_id),
            PRIMARY KEY(supplier_id, risk_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE supplier_document (
            supplier_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX IDX_SUPPLIER_DOCUMENT_SUPPLIER (supplier_id),
            INDEX IDX_SUPPLIER_DOCUMENT_DOCUMENT (document_id),
            PRIMARY KEY(supplier_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Interested Party table
        $this->addSql('CREATE TABLE interested_party (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            party_type VARCHAR(100) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            contact_person VARCHAR(100) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            importance VARCHAR(50) NOT NULL,
            requirements LONGTEXT NOT NULL,
            legal_requirements JSON DEFAULT NULL,
            how_addressed LONGTEXT DEFAULT NULL,
            communication_frequency VARCHAR(100) DEFAULT NULL,
            communication_method LONGTEXT DEFAULT NULL,
            last_communication DATE DEFAULT NULL,
            next_communication DATE DEFAULT NULL,
            feedback LONGTEXT DEFAULT NULL,
            satisfaction_level INT DEFAULT NULL,
            issues LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_party_type (party_type),
            INDEX idx_party_importance (importance),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Business Continuity Plan table
        $this->addSql('CREATE TABLE business_continuity_plan (
            id INT AUTO_INCREMENT NOT NULL,
            business_process_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            plan_owner VARCHAR(100) NOT NULL,
            bc_team LONGTEXT DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            activation_criteria LONGTEXT NOT NULL,
            roles_and_responsibilities LONGTEXT DEFAULT NULL,
            response_team JSON DEFAULT NULL,
            recovery_procedures LONGTEXT NOT NULL,
            communication_plan LONGTEXT DEFAULT NULL,
            internal_communication LONGTEXT DEFAULT NULL,
            external_communication LONGTEXT DEFAULT NULL,
            stakeholder_contacts JSON DEFAULT NULL,
            alternative_site LONGTEXT DEFAULT NULL,
            alternative_site_address LONGTEXT DEFAULT NULL,
            alternative_site_capacity LONGTEXT DEFAULT NULL,
            backup_procedures LONGTEXT DEFAULT NULL,
            restore_procedures LONGTEXT DEFAULT NULL,
            required_resources JSON DEFAULT NULL,
            version VARCHAR(20) NOT NULL,
            last_tested DATE DEFAULT NULL,
            next_test_date DATE DEFAULT NULL,
            last_review_date DATE DEFAULT NULL,
            next_review_date DATE DEFAULT NULL,
            review_notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_bc_plan_status (status),
            INDEX idx_bc_plan_last_tested (last_tested),
            INDEX idx_bc_plan_next_review (next_review_date),
            INDEX IDX_BC_PLAN_BUSINESS_PROCESS (business_process_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Join tables for Business Continuity Plan
        $this->addSql('CREATE TABLE bc_plan_supplier (
            business_continuity_plan_id INT NOT NULL,
            supplier_id INT NOT NULL,
            INDEX IDX_BC_PLAN_SUPPLIER_PLAN (business_continuity_plan_id),
            INDEX IDX_BC_PLAN_SUPPLIER_SUPPLIER (supplier_id),
            PRIMARY KEY(business_continuity_plan_id, supplier_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bc_plan_asset (
            business_continuity_plan_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_BC_PLAN_ASSET_PLAN (business_continuity_plan_id),
            INDEX IDX_BC_PLAN_ASSET_ASSET (asset_id),
            PRIMARY KEY(business_continuity_plan_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bc_plan_document (
            business_continuity_plan_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX IDX_BC_PLAN_DOCUMENT_PLAN (business_continuity_plan_id),
            INDEX IDX_BC_PLAN_DOCUMENT_DOCUMENT (document_id),
            PRIMARY KEY(business_continuity_plan_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // BC Exercise table
        $this->addSql('CREATE TABLE bc_exercise (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            exercise_type VARCHAR(50) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            scope LONGTEXT NOT NULL,
            objectives LONGTEXT NOT NULL,
            scenario LONGTEXT DEFAULT NULL,
            exercise_date DATE NOT NULL,
            duration_hours INT DEFAULT NULL,
            participants LONGTEXT NOT NULL,
            facilitator VARCHAR(100) NOT NULL,
            observers LONGTEXT DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            results LONGTEXT DEFAULT NULL,
            what_went_well LONGTEXT DEFAULT NULL,
            areas_for_improvement LONGTEXT DEFAULT NULL,
            findings LONGTEXT DEFAULT NULL,
            action_items LONGTEXT DEFAULT NULL,
            lessons_learned LONGTEXT DEFAULT NULL,
            plan_updates_required LONGTEXT DEFAULT NULL,
            success_criteria JSON DEFAULT NULL,
            success_rating INT DEFAULT NULL,
            report_completed TINYINT(1) NOT NULL,
            report_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_bc_exercise_type (exercise_type),
            INDEX idx_bc_exercise_date (exercise_date),
            INDEX idx_bc_exercise_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Join tables for BC Exercise
        $this->addSql('CREATE TABLE bc_exercise_plan (
            bc_exercise_id INT NOT NULL,
            business_continuity_plan_id INT NOT NULL,
            INDEX IDX_BC_EXERCISE_PLAN_EXERCISE (bc_exercise_id),
            INDEX IDX_BC_EXERCISE_PLAN_PLAN (business_continuity_plan_id),
            PRIMARY KEY(bc_exercise_id, business_continuity_plan_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bc_exercise_document (
            bc_exercise_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX IDX_BC_EXERCISE_DOCUMENT_EXERCISE (bc_exercise_id),
            INDEX IDX_BC_EXERCISE_DOCUMENT_DOCUMENT (document_id),
            PRIMARY KEY(bc_exercise_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Change Request table
        $this->addSql('CREATE TABLE change_request (
            id INT AUTO_INCREMENT NOT NULL,
            change_number VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            change_type VARCHAR(50) NOT NULL,
            description LONGTEXT NOT NULL,
            justification LONGTEXT NOT NULL,
            requested_by VARCHAR(100) NOT NULL,
            requested_date DATE NOT NULL,
            priority VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL,
            isms_impact LONGTEXT DEFAULT NULL,
            risk_assessment LONGTEXT DEFAULT NULL,
            implementation_plan LONGTEXT DEFAULT NULL,
            rollback_plan LONGTEXT DEFAULT NULL,
            testing_requirements LONGTEXT DEFAULT NULL,
            planned_implementation_date DATE DEFAULT NULL,
            actual_implementation_date DATE DEFAULT NULL,
            approved_by VARCHAR(100) DEFAULT NULL,
            approved_date DATE DEFAULT NULL,
            approval_comments LONGTEXT DEFAULT NULL,
            implemented_by VARCHAR(100) DEFAULT NULL,
            implementation_notes LONGTEXT DEFAULT NULL,
            verified_by VARCHAR(100) DEFAULT NULL,
            verified_date DATE DEFAULT NULL,
            verification_results LONGTEXT DEFAULT NULL,
            closed_date DATE DEFAULT NULL,
            closure_notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_change_type (change_type),
            INDEX idx_change_priority (priority),
            INDEX idx_change_status (status),
            INDEX idx_change_planned_date (planned_implementation_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Join tables for Change Request
        $this->addSql('CREATE TABLE change_request_asset (
            change_request_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_CHANGE_REQUEST_ASSET_CHANGE (change_request_id),
            INDEX IDX_CHANGE_REQUEST_ASSET_ASSET (asset_id),
            PRIMARY KEY(change_request_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE change_request_control (
            change_request_id INT NOT NULL,
            control_id INT NOT NULL,
            INDEX IDX_CHANGE_REQUEST_CONTROL_CHANGE (change_request_id),
            INDEX IDX_CHANGE_REQUEST_CONTROL_CONTROL (control_id),
            PRIMARY KEY(change_request_id, control_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE change_request_business_process (
            change_request_id INT NOT NULL,
            business_process_id INT NOT NULL,
            INDEX IDX_CHANGE_REQUEST_PROCESS_CHANGE (change_request_id),
            INDEX IDX_CHANGE_REQUEST_PROCESS_PROCESS (business_process_id),
            PRIMARY KEY(change_request_id, business_process_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE change_request_risk (
            change_request_id INT NOT NULL,
            risk_id INT NOT NULL,
            INDEX IDX_CHANGE_REQUEST_RISK_CHANGE (change_request_id),
            INDEX IDX_CHANGE_REQUEST_RISK_RISK (risk_id),
            PRIMARY KEY(change_request_id, risk_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE change_request_document (
            change_request_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX IDX_CHANGE_REQUEST_DOCUMENT_CHANGE (change_request_id),
            INDEX IDX_CHANGE_REQUEST_DOCUMENT_DOCUMENT (document_id),
            PRIMARY KEY(change_request_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add Risk Acceptance Approval fields to risk table
        $this->addSql('ALTER TABLE risk ADD acceptance_approved_by VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD acceptance_approved_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD acceptance_justification LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD formally_accepted TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Drop Risk Acceptance Approval fields
        $this->addSql('ALTER TABLE risk DROP acceptance_approved_by');
        $this->addSql('ALTER TABLE risk DROP acceptance_approved_at');
        $this->addSql('ALTER TABLE risk DROP acceptance_justification');
        $this->addSql('ALTER TABLE risk DROP formally_accepted');

        // Drop Change Request tables
        $this->addSql('DROP TABLE change_request_document');
        $this->addSql('DROP TABLE change_request_risk');
        $this->addSql('DROP TABLE change_request_business_process');
        $this->addSql('DROP TABLE change_request_control');
        $this->addSql('DROP TABLE change_request_asset');
        $this->addSql('DROP TABLE change_request');

        // Drop BC Exercise tables
        $this->addSql('DROP TABLE bc_exercise_document');
        $this->addSql('DROP TABLE bc_exercise_plan');
        $this->addSql('DROP TABLE bc_exercise');

        // Drop Business Continuity Plan tables
        $this->addSql('DROP TABLE bc_plan_document');
        $this->addSql('DROP TABLE bc_plan_asset');
        $this->addSql('DROP TABLE bc_plan_supplier');
        $this->addSql('DROP TABLE business_continuity_plan');

        // Drop Interested Party table
        $this->addSql('DROP TABLE interested_party');

        // Drop Supplier tables
        $this->addSql('DROP TABLE supplier_document');
        $this->addSql('DROP TABLE supplier_risk');
        $this->addSql('DROP TABLE supplier_asset');
        $this->addSql('DROP TABLE supplier');
    }
}
