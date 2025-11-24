#!/bin/bash

# Script to fix email template translation issues

BASE_DIR="/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/templates/emails"

echo "Fixing Email templates..."

# audit_due_notification.html.twig
sed -i '' 's|<p>This is an automated reminder from Little ISMS Helper</p>|<p>{{ '\''email.automated_reminder'\''\|trans }}</p>|g' "$BASE_DIR/audit_due_notification.html.twig"
sed -i '' 's|<p>Please ensure all preparations are completed before the audit date</p>|<p>{{ '\''email.ensure_preparations'\''\|trans }}</p>|g' "$BASE_DIR/audit_due_notification.html.twig"

# control_due_notification.html.twig
sed -i '' 's|<p>This is an automated reminder from Little ISMS Helper</p>|<p>{{ '\''email.automated_reminder'\''\|trans }}</p>|g' "$BASE_DIR/control_due_notification.html.twig"
sed -i '' 's|<p>Please ensure the control implementation is completed before the target date</p>|<p>{{ '\''email.ensure_control_completion'\''\|trans }}</p>|g' "$BASE_DIR/control_due_notification.html.twig"

# incident_notification.html.twig
sed -i '' 's|<h1>🚨 New Security Incident Reported</h1>|<h1>🚨 {{ '\''email.new_incident_reported'\''\|trans }}</h1>|g' "$BASE_DIR/incident_notification.html.twig"
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/incident_notification.html.twig"
sed -i '' 's|<p>Please take appropriate action based on the incident severity</p>|<p>{{ '\''email.take_appropriate_action'\''\|trans }}</p>|g' "$BASE_DIR/incident_notification.html.twig"

# incident_update.html.twig
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/incident_update.html.twig"

# risk_acceptance_approved.html.twig
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/risk_acceptance_approved.html.twig"

# risk_acceptance_rejected.html.twig
sed -i '' 's|<li>Review the rejection reason carefully</li>|<li>{{ '\''email.risk_acceptance.review_reason'\''\|trans }}</li>|g' "$BASE_DIR/risk_acceptance_rejected.html.twig"
sed -i '' 's|<li>Identify and implement additional controls to reduce the residual risk</li>|<li>{{ '\''email.risk_acceptance.identify_controls'\''\|trans }}</li>|g' "$BASE_DIR/risk_acceptance_rejected.html.twig"
sed -i '' 's|<li>Update the risk assessment to reflect the new controls</li>|<li>{{ '\''email.risk_acceptance.update_assessment'\''\|trans }}</li>|g' "$BASE_DIR/risk_acceptance_rejected.html.twig"
sed -i '' 's|<li>Re-assess the residual risk level</li>|<li>{{ '\''email.risk_acceptance.reassess_risk'\''\|trans }}</li>|g' "$BASE_DIR/risk_acceptance_rejected.html.twig"
sed -i '' 's|<li>If the risk is sufficiently reduced, you may request acceptance again</li>|<li>{{ '\''email.risk_acceptance.request_again'\''\|trans }}</li>|g' "$BASE_DIR/risk_acceptance_rejected.html.twig"
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/risk_acceptance_rejected.html.twig"

# risk_acceptance_request.html.twig
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/risk_acceptance_request.html.twig"

# training_due_notification.html.twig
sed -i '' 's|<p>This is an automated reminder from Little ISMS Helper</p>|<p>{{ '\''email.automated_reminder'\''\|trans }}</p>|g' "$BASE_DIR/training_due_notification.html.twig"
sed -i '' 's|<p>Please mark your calendar and prepare for the training session</p>|<p>{{ '\''email.training.mark_calendar'\''\|trans }}</p>|g' "$BASE_DIR/training_due_notification.html.twig"

# workflow_assignment_notification.html.twig
sed -i '' 's|<span class="label">Time to Complete:</span>|<span class="label">{{ '\''email.workflow.time_to_complete'\''\|trans }}</span>|g' "$BASE_DIR/workflow_assignment_notification.html.twig"
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/workflow_assignment_notification.html.twig"
sed -i '' 's|<p>Please log in to the system to process this approval request</p>|<p>{{ '\''email.workflow.login_to_process'\''\|trans }}</p>|g' "$BASE_DIR/workflow_assignment_notification.html.twig"

# workflow_deadline_warning.html.twig
sed -i '' 's|<h1>⏰ Deadline Warning</h1>|<h1>⏰ {{ '\''email.workflow.deadline_warning'\''\|trans }}</h1>|g' "$BASE_DIR/workflow_deadline_warning.html.twig"
sed -i '' 's|<p>This is an automated warning from Little ISMS Helper</p>|<p>{{ '\''email.workflow.automated_alert'\''\|trans }}</p>|g' "$BASE_DIR/workflow_deadline_warning.html.twig"
sed -i '' 's|<p>Please log in to the system to review this workflow</p>|<p>{{ '\''email.workflow.login_to_review'\''\|trans }}</p>|g' "$BASE_DIR/workflow_deadline_warning.html.twig"

# workflow_notification_step.html.twig
sed -i '' 's|<h1>ℹ️ Workflow Update</h1>|<h1>ℹ️ {{ '\''email.workflow.workflow_update'\''\|trans }}</h1>|g' "$BASE_DIR/workflow_notification_step.html.twig"
sed -i '' 's|<p>This is an automated notification from Little ISMS Helper</p>|<p>{{ '\''email.automated_notification'\''\|trans }}</p>|g' "$BASE_DIR/workflow_notification_step.html.twig"
sed -i '' 's|<p>You can log in to the system for more details</p>|<p>{{ '\''email.workflow.login_for_details'\''\|trans }}</p>|g' "$BASE_DIR/workflow_notification_step.html.twig"

# workflow_overdue_notification.html.twig
sed -i '' 's|<p>This is an automated alert from Little ISMS Helper</p>|<p>{{ '\''email.workflow.automated_alert'\''\|trans }}</p>|g' "$BASE_DIR/workflow_overdue_notification.html.twig"
sed -i '' 's|<p>Please log in to the system to process this workflow step</p>|<p>{{ '\''email.workflow.login_to_process_step'\''\|trans }}</p>|g' "$BASE_DIR/workflow_overdue_notification.html.twig"

echo "Email templates fixed!"
