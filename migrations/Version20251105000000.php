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
        $asset = $schema->createTable('asset');
        $asset->addColumn('id', 'integer', ['autoincrement' => true]);
        $asset->addColumn('name', 'string', ['length' => 255]);
        $asset->addColumn('description', 'text', ['notnull' => false]);
        $asset->addColumn('asset_type', 'string', ['length' => 100]);
        $asset->addColumn('owner', 'string', ['length' => 100]);
        $asset->addColumn('location', 'string', ['length' => 100, 'notnull' => false]);
        $asset->addColumn('confidentiality_value', 'integer');
        $asset->addColumn('integrity_value', 'integer');
        $asset->addColumn('availability_value', 'integer');
        $asset->addColumn('status', 'string', ['length' => 50]);
        $asset->addColumn('created_at', 'datetime');
        $asset->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $asset->setPrimaryKey(['id']);

        // Risk table
        $risk = $schema->createTable('risk');
        $risk->addColumn('id', 'integer', ['autoincrement' => true]);
        $risk->addColumn('asset_id', 'integer', ['notnull' => false]);
        $risk->addColumn('title', 'string', ['length' => 255]);
        $risk->addColumn('description', 'text');
        $risk->addColumn('threat', 'text', ['notnull' => false]);
        $risk->addColumn('vulnerability', 'text', ['notnull' => false]);
        $risk->addColumn('probability', 'integer');
        $risk->addColumn('impact', 'integer');
        $risk->addColumn('residual_probability', 'integer');
        $risk->addColumn('residual_impact', 'integer');
        $risk->addColumn('treatment_strategy', 'string', ['length' => 50]);
        $risk->addColumn('treatment_description', 'text', ['notnull' => false]);
        $risk->addColumn('risk_owner', 'string', ['length' => 100, 'notnull' => false]);
        $risk->addColumn('status', 'string', ['length' => 50]);
        $risk->addColumn('review_date', 'date', ['notnull' => false]);
        $risk->addColumn('created_at', 'datetime');
        $risk->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $risk->setPrimaryKey(['id']);
        $risk->addIndex(['asset_id'], 'IDX_7906D5415DA1941');
        $risk->addForeignKeyConstraint('asset', ['asset_id'], ['id'], ['onDelete' => 'RESTRICT'], 'FK_ASSET');

        // Control table
        $control = $schema->createTable('control');
        $control->addColumn('id', 'integer', ['autoincrement' => true]);
        $control->addColumn('control_id', 'string', ['length' => 20]);
        $control->addColumn('name', 'string', ['length' => 255]);
        $control->addColumn('description', 'text');
        $control->addColumn('category', 'string', ['length' => 100]);
        $control->addColumn('applicable', 'boolean');
        $control->addColumn('justification', 'text', ['notnull' => false]);
        $control->addColumn('implementation_notes', 'text', ['notnull' => false]);
        $control->addColumn('implementation_status', 'string', ['length' => 50]);
        $control->addColumn('implementation_percentage', 'integer', ['notnull' => false]);
        $control->addColumn('responsible_person', 'string', ['length' => 100, 'notnull' => false]);
        $control->addColumn('target_date', 'date', ['notnull' => false]);
        $control->addColumn('last_review_date', 'date', ['notnull' => false]);
        $control->addColumn('next_review_date', 'date', ['notnull' => false]);
        $control->addColumn('created_at', 'datetime');
        $control->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $control->setPrimaryKey(['id']);

        // Control-Risk many-to-many
        $controlRisk = $schema->createTable('control_risk');
        $controlRisk->addColumn('control_id', 'integer');
        $controlRisk->addColumn('risk_id', 'integer');
        $controlRisk->setPrimaryKey(['control_id', 'risk_id']);
        $controlRisk->addIndex(['control_id'], 'IDX_CONTROL');
        $controlRisk->addIndex(['risk_id'], 'IDX_RISK');
        $controlRisk->addForeignKeyConstraint('control', ['control_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CR_CONTROL');
        $controlRisk->addForeignKeyConstraint('risk', ['risk_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CR_RISK');

        // Incident table
        $incident = $schema->createTable('incident');
        $incident->addColumn('id', 'integer', ['autoincrement' => true]);
        $incident->addColumn('incident_number', 'string', ['length' => 50]);
        $incident->addColumn('title', 'string', ['length' => 255]);
        $incident->addColumn('description', 'text');
        $incident->addColumn('category', 'string', ['length' => 100]);
        $incident->addColumn('severity', 'string', ['length' => 50]);
        $incident->addColumn('status', 'string', ['length' => 50]);
        $incident->addColumn('detected_at', 'datetime');
        $incident->addColumn('occurred_at', 'datetime', ['notnull' => false]);
        $incident->addColumn('reported_by', 'string', ['length' => 100]);
        $incident->addColumn('assigned_to', 'string', ['length' => 100, 'notnull' => false]);
        $incident->addColumn('immediate_actions', 'text', ['notnull' => false]);
        $incident->addColumn('root_cause', 'text', ['notnull' => false]);
        $incident->addColumn('corrective_actions', 'text', ['notnull' => false]);
        $incident->addColumn('preventive_actions', 'text', ['notnull' => false]);
        $incident->addColumn('lessons_learned', 'text', ['notnull' => false]);
        $incident->addColumn('resolved_at', 'datetime', ['notnull' => false]);
        $incident->addColumn('closed_at', 'datetime', ['notnull' => false]);
        $incident->addColumn('data_breach_occurred', 'boolean');
        $incident->addColumn('notification_required', 'boolean');
        $incident->addColumn('created_at', 'datetime');
        $incident->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $incident->setPrimaryKey(['id']);

        // Incident-Control many-to-many
        $incidentControl = $schema->createTable('incident_control');
        $incidentControl->addColumn('incident_id', 'integer');
        $incidentControl->addColumn('control_id', 'integer');
        $incidentControl->setPrimaryKey(['incident_id', 'control_id']);
        $incidentControl->addIndex(['incident_id'], 'IDX_INCIDENT');
        $incidentControl->addIndex(['control_id'], 'IDX_CONTROL2');
        $incidentControl->addForeignKeyConstraint('incident', ['incident_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_IC_INCIDENT');
        $incidentControl->addForeignKeyConstraint('control', ['control_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_IC_CONTROL');

        // Internal Audit table
        $internalAudit = $schema->createTable('internal_audit');
        $internalAudit->addColumn('id', 'integer', ['autoincrement' => true]);
        $internalAudit->addColumn('audit_number', 'string', ['length' => 50]);
        $internalAudit->addColumn('title', 'string', ['length' => 255]);
        $internalAudit->addColumn('scope', 'text', ['notnull' => false]);
        $internalAudit->addColumn('objectives', 'text', ['notnull' => false]);
        $internalAudit->addColumn('planned_date', 'date');
        $internalAudit->addColumn('actual_date', 'date', ['notnull' => false]);
        $internalAudit->addColumn('lead_auditor', 'string', ['length' => 100]);
        $internalAudit->addColumn('audit_team', 'text', ['notnull' => false]);
        $internalAudit->addColumn('audited_departments', 'text', ['notnull' => false]);
        $internalAudit->addColumn('status', 'string', ['length' => 50]);
        $internalAudit->addColumn('findings', 'text', ['notnull' => false]);
        $internalAudit->addColumn('non_conformities', 'text', ['notnull' => false]);
        $internalAudit->addColumn('observations', 'text', ['notnull' => false]);
        $internalAudit->addColumn('recommendations', 'text', ['notnull' => false]);
        $internalAudit->addColumn('conclusion', 'text', ['notnull' => false]);
        $internalAudit->addColumn('report_date', 'date', ['notnull' => false]);
        $internalAudit->addColumn('created_at', 'datetime');
        $internalAudit->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $internalAudit->setPrimaryKey(['id']);

        // Management Review table
        $managementReview = $schema->createTable('management_review');
        $managementReview->addColumn('id', 'integer', ['autoincrement' => true]);
        $managementReview->addColumn('title', 'string', ['length' => 255]);
        $managementReview->addColumn('review_date', 'date');
        $managementReview->addColumn('participants', 'text', ['notnull' => false]);
        $managementReview->addColumn('changes_relevant_to_isms', 'text', ['notnull' => false]);
        $managementReview->addColumn('feedback_from_interested_parties', 'text', ['notnull' => false]);
        $managementReview->addColumn('audit_results', 'text', ['notnull' => false]);
        $managementReview->addColumn('performance_evaluation', 'text', ['notnull' => false]);
        $managementReview->addColumn('non_conformities_status', 'text', ['notnull' => false]);
        $managementReview->addColumn('corrective_actions_status', 'text', ['notnull' => false]);
        $managementReview->addColumn('previous_review_actions', 'text', ['notnull' => false]);
        $managementReview->addColumn('opportunities_for_improvement', 'text', ['notnull' => false]);
        $managementReview->addColumn('resource_needs', 'text', ['notnull' => false]);
        $managementReview->addColumn('decisions', 'text', ['notnull' => false]);
        $managementReview->addColumn('action_items', 'text', ['notnull' => false]);
        $managementReview->addColumn('status', 'string', ['length' => 50]);
        $managementReview->addColumn('created_at', 'datetime');
        $managementReview->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $managementReview->setPrimaryKey(['id']);

        // Training table
        $training = $schema->createTable('training');
        $training->addColumn('id', 'integer', ['autoincrement' => true]);
        $training->addColumn('title', 'string', ['length' => 255]);
        $training->addColumn('description', 'text', ['notnull' => false]);
        $training->addColumn('training_type', 'string', ['length' => 100]);
        $training->addColumn('scheduled_date', 'date');
        $training->addColumn('duration_minutes', 'integer', ['notnull' => false]);
        $training->addColumn('trainer', 'string', ['length' => 100]);
        $training->addColumn('target_audience', 'text', ['notnull' => false]);
        $training->addColumn('participants', 'text', ['notnull' => false]);
        $training->addColumn('attendee_count', 'integer', ['notnull' => false]);
        $training->addColumn('status', 'string', ['length' => 50]);
        $training->addColumn('materials', 'text', ['notnull' => false]);
        $training->addColumn('feedback', 'text', ['notnull' => false]);
        $training->addColumn('completion_date', 'date', ['notnull' => false]);
        $training->addColumn('created_at', 'datetime');
        $training->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $training->setPrimaryKey(['id']);

        // ISMS Context table
        $ismsContext = $schema->createTable('ismscontext');
        $ismsContext->addColumn('id', 'integer', ['autoincrement' => true]);
        $ismsContext->addColumn('organization_name', 'string', ['length' => 255]);
        $ismsContext->addColumn('isms_scope', 'text', ['notnull' => false]);
        $ismsContext->addColumn('scope_exclusions', 'text', ['notnull' => false]);
        $ismsContext->addColumn('external_issues', 'text', ['notnull' => false]);
        $ismsContext->addColumn('internal_issues', 'text', ['notnull' => false]);
        $ismsContext->addColumn('interested_parties', 'text', ['notnull' => false]);
        $ismsContext->addColumn('interested_parties_requirements', 'text', ['notnull' => false]);
        $ismsContext->addColumn('legal_requirements', 'text', ['notnull' => false]);
        $ismsContext->addColumn('regulatory_requirements', 'text', ['notnull' => false]);
        $ismsContext->addColumn('contractual_obligations', 'text', ['notnull' => false]);
        $ismsContext->addColumn('isms_policy', 'text', ['notnull' => false]);
        $ismsContext->addColumn('roles_and_responsibilities', 'text', ['notnull' => false]);
        $ismsContext->addColumn('last_review_date', 'date', ['notnull' => false]);
        $ismsContext->addColumn('next_review_date', 'date', ['notnull' => false]);
        $ismsContext->addColumn('created_at', 'datetime');
        $ismsContext->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $ismsContext->setPrimaryKey(['id']);

        // ISMS Objective table
        $ismsObjective = $schema->createTable('ismsobjective');
        $ismsObjective->addColumn('id', 'integer', ['autoincrement' => true]);
        $ismsObjective->addColumn('title', 'string', ['length' => 255]);
        $ismsObjective->addColumn('description', 'text');
        $ismsObjective->addColumn('category', 'string', ['length' => 100]);
        $ismsObjective->addColumn('measurable_indicators', 'text', ['notnull' => false]);
        $ismsObjective->addColumn('target_value', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => false]);
        $ismsObjective->addColumn('current_value', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => false]);
        $ismsObjective->addColumn('unit', 'string', ['length' => 50, 'notnull' => false]);
        $ismsObjective->addColumn('responsible_person', 'string', ['length' => 100]);
        $ismsObjective->addColumn('target_date', 'date');
        $ismsObjective->addColumn('status', 'string', ['length' => 50]);
        $ismsObjective->addColumn('progress_notes', 'text', ['notnull' => false]);
        $ismsObjective->addColumn('achieved_date', 'date', ['notnull' => false]);
        $ismsObjective->addColumn('created_at', 'datetime');
        $ismsObjective->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $ismsObjective->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('incident_control');
        $schema->dropTable('control_risk');
        $schema->dropTable('ismsobjective');
        $schema->dropTable('ismscontext');
        $schema->dropTable('training');
        $schema->dropTable('management_review');
        $schema->dropTable('internal_audit');
        $schema->dropTable('incident');
        $schema->dropTable('control');
        $schema->dropTable('risk');
        $schema->dropTable('asset');
    }
}
