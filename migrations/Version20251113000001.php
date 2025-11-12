<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add tenant_id foreign keys to all tenant-scoped entities for complete multi-tenancy
 */
final class Version20251113000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant_id foreign keys to 12 entities for complete multi-tenancy support';
    }

    public function up(Schema $schema): void
    {
        // Add tenant_id columns and foreign keys (DEFAULT NULL for backward compatibility)
        $this->addSql('ALTER TABLE business_process ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_context ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_objective ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internal_audit ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE management_review ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE training ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vulnerabilities ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE patches ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE crisis_teams ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_checklist ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workflows ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_instances ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_steps ADD tenant_id INT DEFAULT NULL');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE business_process ADD CONSTRAINT FK_business_process_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE isms_context ADD CONSTRAINT FK_isms_context_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE isms_objective ADD CONSTRAINT FK_isms_objective_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE internal_audit ADD CONSTRAINT FK_internal_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_management_review_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE training ADD CONSTRAINT FK_training_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE vulnerabilities ADD CONSTRAINT FK_vulnerabilities_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE patches ADD CONSTRAINT FK_patches_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE crisis_teams ADD CONSTRAINT FK_crisis_teams_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE audit_checklist ADD CONSTRAINT FK_audit_checklist_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE workflows ADD CONSTRAINT FK_workflows_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE workflow_instances ADD CONSTRAINT FK_workflow_instances_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE workflow_steps ADD CONSTRAINT FK_workflow_steps_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Add indexes for better query performance
        $this->addSql('CREATE INDEX IDX_business_process_tenant ON business_process (tenant_id)');
        $this->addSql('CREATE INDEX IDX_isms_context_tenant ON isms_context (tenant_id)');
        $this->addSql('CREATE INDEX IDX_isms_objective_tenant ON isms_objective (tenant_id)');
        $this->addSql('CREATE INDEX IDX_internal_audit_tenant ON internal_audit (tenant_id)');
        $this->addSql('CREATE INDEX IDX_management_review_tenant ON management_review (tenant_id)');
        $this->addSql('CREATE INDEX IDX_training_tenant ON training (tenant_id)');
        $this->addSql('CREATE INDEX IDX_vulnerabilities_tenant ON vulnerabilities (tenant_id)');
        $this->addSql('CREATE INDEX IDX_patches_tenant ON patches (tenant_id)');
        $this->addSql('CREATE INDEX IDX_crisis_teams_tenant ON crisis_teams (tenant_id)');
        $this->addSql('CREATE INDEX IDX_audit_checklist_tenant ON audit_checklist (tenant_id)');
        $this->addSql('CREATE INDEX IDX_workflows_tenant ON workflows (tenant_id)');
        $this->addSql('CREATE INDEX IDX_workflow_instances_tenant ON workflow_instances (tenant_id)');
        $this->addSql('CREATE INDEX IDX_workflow_steps_tenant ON workflow_steps (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys
        $this->addSql('ALTER TABLE business_process DROP FOREIGN KEY FK_business_process_tenant');
        $this->addSql('ALTER TABLE isms_context DROP FOREIGN KEY FK_isms_context_tenant');
        $this->addSql('ALTER TABLE isms_objective DROP FOREIGN KEY FK_isms_objective_tenant');
        $this->addSql('ALTER TABLE internal_audit DROP FOREIGN KEY FK_internal_audit_tenant');
        $this->addSql('ALTER TABLE management_review DROP FOREIGN KEY FK_management_review_tenant');
        $this->addSql('ALTER TABLE training DROP FOREIGN KEY FK_training_tenant');
        $this->addSql('ALTER TABLE vulnerabilities DROP FOREIGN KEY FK_vulnerabilities_tenant');
        $this->addSql('ALTER TABLE patches DROP FOREIGN KEY FK_patches_tenant');
        $this->addSql('ALTER TABLE crisis_teams DROP FOREIGN KEY FK_crisis_teams_tenant');
        $this->addSql('ALTER TABLE audit_checklist DROP FOREIGN KEY FK_audit_checklist_tenant');
        $this->addSql('ALTER TABLE workflows DROP FOREIGN KEY FK_workflows_tenant');
        $this->addSql('ALTER TABLE workflow_instances DROP FOREIGN KEY FK_workflow_instances_tenant');
        $this->addSql('ALTER TABLE workflow_steps DROP FOREIGN KEY FK_workflow_steps_tenant');

        // Drop indexes
        $this->addSql('DROP INDEX IDX_business_process_tenant ON business_process');
        $this->addSql('DROP INDEX IDX_isms_context_tenant ON isms_context');
        $this->addSql('DROP INDEX IDX_isms_objective_tenant ON isms_objective');
        $this->addSql('DROP INDEX IDX_internal_audit_tenant ON internal_audit');
        $this->addSql('DROP INDEX IDX_management_review_tenant ON management_review');
        $this->addSql('DROP INDEX IDX_training_tenant ON training');
        $this->addSql('DROP INDEX IDX_vulnerabilities_tenant ON vulnerabilities');
        $this->addSql('DROP INDEX IDX_patches_tenant ON patches');
        $this->addSql('DROP INDEX IDX_crisis_teams_tenant ON crisis_teams');
        $this->addSql('DROP INDEX IDX_audit_checklist_tenant ON audit_checklist');
        $this->addSql('DROP INDEX IDX_workflows_tenant ON workflows');
        $this->addSql('DROP INDEX IDX_workflow_instances_tenant ON workflow_instances');
        $this->addSql('DROP INDEX IDX_workflow_steps_tenant ON workflow_steps');

        // Drop columns
        $this->addSql('ALTER TABLE business_process DROP tenant_id');
        $this->addSql('ALTER TABLE isms_context DROP tenant_id');
        $this->addSql('ALTER TABLE isms_objective DROP tenant_id');
        $this->addSql('ALTER TABLE internal_audit DROP tenant_id');
        $this->addSql('ALTER TABLE management_review DROP tenant_id');
        $this->addSql('ALTER TABLE training DROP tenant_id');
        $this->addSql('ALTER TABLE vulnerabilities DROP tenant_id');
        $this->addSql('ALTER TABLE patches DROP tenant_id');
        $this->addSql('ALTER TABLE crisis_teams DROP tenant_id');
        $this->addSql('ALTER TABLE audit_checklist DROP tenant_id');
        $this->addSql('ALTER TABLE workflows DROP tenant_id');
        $this->addSql('ALTER TABLE workflow_instances DROP tenant_id');
        $this->addSql('ALTER TABLE workflow_steps DROP tenant_id');
    }
}
