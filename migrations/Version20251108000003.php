<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add tenant support to ISO compliance entities
 *
 * Adds tenant_id column and index to:
 * - supplier
 * - interested_party
 * - business_continuity_plan
 * - bc_exercise
 * - change_request
 */
final class Version20251108000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant support to ISO compliance entities (Supplier, InterestedParty, BusinessContinuityPlan, BCExercise, ChangeRequest)';
    }

    public function up(Schema $schema): void
    {
        // Add tenant_id to supplier
        $this->addSql('ALTER TABLE supplier ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier ADD INDEX idx_supplier_tenant (tenant_id)');

        // Add tenant_id to interested_party
        $this->addSql('ALTER TABLE interested_party ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE interested_party ADD INDEX idx_interested_party_tenant (tenant_id)');

        // Add tenant_id to business_continuity_plan
        $this->addSql('ALTER TABLE business_continuity_plan ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE business_continuity_plan ADD INDEX idx_bc_plan_tenant (tenant_id)');

        // Add tenant_id to bc_exercise
        $this->addSql('ALTER TABLE bc_exercise ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bc_exercise ADD INDEX idx_bc_exercise_tenant (tenant_id)');

        // Add tenant_id to change_request
        $this->addSql('ALTER TABLE change_request ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE change_request ADD INDEX idx_change_request_tenant (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop indices first, then columns for supplier
        $this->addSql('DROP INDEX idx_supplier_tenant ON supplier');
        $this->addSql('ALTER TABLE supplier DROP tenant_id');

        // Drop indices first, then columns for interested_party
        $this->addSql('DROP INDEX idx_interested_party_tenant ON interested_party');
        $this->addSql('ALTER TABLE interested_party DROP tenant_id');

        // Drop indices first, then columns for business_continuity_plan
        $this->addSql('DROP INDEX idx_bc_plan_tenant ON business_continuity_plan');
        $this->addSql('ALTER TABLE business_continuity_plan DROP tenant_id');

        // Drop indices first, then columns for bc_exercise
        $this->addSql('DROP INDEX idx_bc_exercise_tenant ON bc_exercise');
        $this->addSql('ALTER TABLE bc_exercise DROP tenant_id');

        // Drop indices first, then columns for change_request
        $this->addSql('DROP INDEX idx_change_request_tenant ON change_request');
        $this->addSql('ALTER TABLE change_request DROP tenant_id');
    }
}
