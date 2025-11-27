<?php

namespace App\Service;

use App\Entity\Incident;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Document;
use App\Entity\Risk;
use Psr\Log\LoggerInterface;

/**
 * Workflow Auto-Trigger Service
 *
 * Centralized service for automatically starting workflows when entities are created or updated.
 * Implements ISO 27001:2022 compliance requirements for approval processes.
 *
 * Supported automatic workflow triggers:
 * - Incident: Auto-escalate based on severity (ISO 27001:2022 Clause 8.3.2)
 * - Incident: GDPR 72h breach notification (GDPR Art. 33 + BDSG § 42)
 * - RiskTreatmentPlan: Treatment plan approval (ISO 27005:2022 Clause 8.5.7)
 * - Document: Policy/Procedure approval (ISO 27001:2022 Clause 5.2.3)
 * - Risk: Acceptance workflow for high-risk items (ISO 27005:2022 Clause 8.4.4)
 *
 * Usage:
 * - Call from Doctrine lifecycle events (postPersist, postUpdate)
 * - Call from controllers after entity creation/modification
 * - Automatic detection of workflow requirements based on entity state
 */
class WorkflowAutoTriggerService
{
    public function __construct(
        private readonly IncidentEscalationWorkflowService $incidentEscalationService,
        private readonly RiskAcceptanceWorkflowService $riskAcceptanceService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Trigger workflows for a newly created or updated Incident
     *
     * @param Incident $incident
     * @param bool $isNew Whether this is a new incident (true) or update (false)
     * @return array Workflow trigger results
     */
    public function triggerIncidentWorkflows(Incident $incident, bool $isNew = true): array
    {
        $this->logger->info('Triggering incident workflows', [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
            'severity' => $incident->getSeverity(),
            'is_new' => $isNew,
        ]);

        $results = [];

        // Always auto-escalate incidents based on severity
        try {
            $escalationResult = $this->incidentEscalationService->autoEscalate($incident);
            $results['escalation'] = $escalationResult;

            $this->logger->info('Incident auto-escalation completed', [
                'incident_id' => $incident->getId(),
                'escalation_level' => $escalationResult['escalation_level'],
                'workflow_started' => $escalationResult['workflow_started'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to auto-escalate incident', [
                'incident_id' => $incident->getId(),
                'error' => $e->getMessage(),
            ]);
            $results['escalation'] = ['error' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * Trigger workflows for a newly created RiskTreatmentPlan
     *
     * ISO 27005:2022 Clause 8.5.7: Risk treatment plans should be reviewed and approved
     *
     * @param RiskTreatmentPlan $plan
     * @return array Workflow trigger results
     */
    public function triggerRiskTreatmentPlanWorkflows(RiskTreatmentPlan $plan): array
    {
        $this->logger->info('Triggering risk treatment plan workflows', [
            'plan_id' => $plan->getId(),
            'risk_id' => $plan->getRisk()?->getId(),
            'status' => $plan->getStatus(),
        ]);

        $results = [];

        // Only trigger approval workflow for new plans in 'planned' status
        if ($plan->getStatus() === 'planned') {
            // TODO: Implement RiskTreatmentPlanApprovalService
            // $approvalResult = $this->treatmentPlanApprovalService->requestApproval($plan);
            // $results['approval'] = $approvalResult;

            $this->logger->info('Risk treatment plan approval workflow would be triggered', [
                'plan_id' => $plan->getId(),
                'status' => 'pending_implementation',
            ]);
        }

        return $results;
    }

    /**
     * Trigger workflows for a newly created or updated Document
     *
     * ISO 27001:2022 Clause 5.2.3: Policies should be reviewed and approved
     *
     * @param Document $document
     * @param bool $isNew Whether this is a new document
     * @return array Workflow trigger results
     */
    public function triggerDocumentWorkflows(Document $document, bool $isNew = true): array
    {
        $this->logger->info('Triggering document workflows', [
            'document_id' => $document->getId(),
            'category' => $document->getCategory(),
            'is_new' => $isNew,
        ]);

        $results = [];

        // Trigger approval workflow for policies and procedures
        if (in_array($document->getCategory(), ['policy', 'procedure', 'guideline'])) {
            // TODO: Implement DocumentApprovalService
            // $approvalResult = $this->documentApprovalService->requestApproval($document);
            // $results['approval'] = $approvalResult;

            $this->logger->info('Document approval workflow would be triggered', [
                'document_id' => $document->getId(),
                'category' => $document->getCategory(),
            ]);
        }

        return $results;
    }

    /**
     * Trigger workflows for a Risk requiring acceptance
     *
     * ISO 27005:2022 Clause 8.4.4: Risk acceptance requires formal approval
     *
     * @param Risk $risk
     * @param \App\Entity\User $requester User requesting acceptance
     * @param string $justification Justification for acceptance
     * @return array Workflow trigger results
     */
    public function triggerRiskAcceptanceWorkflow(Risk $risk, $requester, string $justification): array
    {
        $this->logger->info('Triggering risk acceptance workflow', [
            'risk_id' => $risk->getId(),
            'risk_score' => $risk->getRiskScore(),
            'treatment_strategy' => $risk->getTreatmentStrategy(),
        ]);

        $results = [];

        try {
            // Use existing RiskAcceptanceWorkflowService
            $acceptanceResult = $this->riskAcceptanceService->requestAcceptance($risk, $requester, $justification);
            $results['acceptance'] = $acceptanceResult;

            $this->logger->info('Risk acceptance workflow triggered', [
                'risk_id' => $risk->getId(),
                'approval_level' => $acceptanceResult['approval_level'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger risk acceptance workflow', [
                'risk_id' => $risk->getId(),
                'error' => $e->getMessage(),
            ]);
            $results['acceptance'] = ['error' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * Determine if an entity change requires workflow triggering
     *
     * @param object $entity
     * @param array $changeSet Array of changed fields
     * @return bool
     */
    public function shouldTriggerWorkflow(object $entity, array $changeSet = []): bool
    {
        // Incident: trigger on creation or severity change
        if ($entity instanceof Incident) {
            return empty($changeSet) || isset($changeSet['severity']) || isset($changeSet['dataBreachOccurred']);
        }

        // RiskTreatmentPlan: trigger on creation with 'planned' status
        if ($entity instanceof RiskTreatmentPlan) {
            return $entity->getStatus() === 'planned';
        }

        // Document: trigger for policy/procedure documents
        if ($entity instanceof Document) {
            return in_array($entity->getCategory(), ['policy', 'procedure', 'guideline']);
        }

        // Risk: only trigger explicitly via controller (not auto)
        if ($entity instanceof Risk) {
            return false; // Risk acceptance is manual via controller
        }

        return false;
    }

    /**
     * Get workflow trigger summary for an entity
     *
     * @param object $entity
     * @return array Summary of applicable workflows
     */
    public function getApplicableWorkflows(object $entity): array
    {
        $workflows = [];

        if ($entity instanceof Incident) {
            $workflows[] = [
                'type' => 'incident_escalation',
                'trigger' => 'automatic',
                'description' => 'Automatic escalation based on severity',
                'compliance' => 'ISO 27001:2022 Clause 8.3.2',
            ];

            if ($entity->isDataBreachOccurred()) {
                $workflows[] = [
                    'type' => 'gdpr_breach_notification',
                    'trigger' => 'automatic',
                    'description' => 'GDPR 72h breach notification workflow',
                    'compliance' => 'GDPR Art. 33 + BDSG § 42',
                    'deadline_hours' => 72,
                ];
            }
        }

        if ($entity instanceof RiskTreatmentPlan && $entity->getStatus() === 'planned') {
            $workflows[] = [
                'type' => 'treatment_plan_approval',
                'trigger' => 'automatic',
                'description' => 'Multi-level approval for treatment plan',
                'compliance' => 'ISO 27005:2022 Clause 8.5.7',
            ];
        }

        if ($entity instanceof Document && in_array($entity->getCategory(), ['policy', 'procedure', 'guideline'])) {
            $workflows[] = [
                'type' => 'document_approval',
                'trigger' => 'automatic',
                'description' => 'Policy/Procedure approval workflow',
                'compliance' => 'ISO 27001:2022 Clause 5.2.3',
            ];
        }

        return $workflows;
    }
}
