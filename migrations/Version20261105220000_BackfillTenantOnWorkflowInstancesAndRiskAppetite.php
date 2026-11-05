<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfills tenant_id on rows that were created without one.
 *
 * workflow_instances: WorkflowService::startWorkflow() never set the tenant,
 * so the TenantFilter hid every instance from its own organisation (approval
 * inbox, My Day, overdue lists). The tenant comes from the record the
 * workflow runs on, else from the initiating user.
 *
 * risk_appetite: RiskAppetiteController::new() never set the tenant either.
 * Rows without a tenant get the tenant of the only organisation when the
 * instance has exactly one; on multi-tenant instances they stay as they are
 * and show up in /quick-fix as orphans to assign.
 */
final class Version20261105220000_BackfillTenantOnWorkflowInstancesAndRiskAppetite extends AbstractMigration
{
    /** workflow entity_type => table */
    private const ENTITY_TABLES = [
        'DataBreach' => 'data_breach',
        'Incident' => 'incident',
        'Risk' => 'risk',
        'DPIA' => 'data_protection_impact_assessment',
        'DataSubjectRequest' => 'data_subject_request',
        'CorrectiveAction' => 'corrective_actions',
        'ChangeRequest' => 'change_request',
        'ManagementReview' => 'management_review',
        'Control' => 'control',
        'Supplier' => 'supplier',
        'Training' => 'training',
        'BusinessContinuityPlan' => 'business_continuity_plan',
        'Document' => 'document',
        'AccessReviewCampaign' => 'access_review_campaign',
        'AuditFinding' => 'audit_findings',
    ];

    public function getDescription(): string
    {
        return 'Backfill tenant_id on workflow_instances and risk_appetite rows created without one';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ENTITY_TABLES as $type => $table) {
            $this->addSql(sprintf(
                'UPDATE workflow_instances wi JOIN %s e ON e.id = wi.entity_id'
                . ' SET wi.tenant_id = e.tenant_id'
                . ' WHERE wi.tenant_id IS NULL AND wi.entity_type = %s AND e.tenant_id IS NOT NULL',
                $table,
                $this->connection->quote($type),
            ));
        }
        $this->addSql(
            'UPDATE workflow_instances wi JOIN users u ON u.id = wi.initiated_by_id'
            . ' SET wi.tenant_id = u.tenant_id'
            . ' WHERE wi.tenant_id IS NULL AND u.tenant_id IS NOT NULL'
        );

        $this->addSql(
            'UPDATE risk_appetite SET tenant_id = (SELECT MIN(id) FROM tenant)'
            . ' WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1'
        );
    }

    public function down(Schema $schema): void
    {
        // Data backfill; the previous NULLs carried no information.
    }
}
