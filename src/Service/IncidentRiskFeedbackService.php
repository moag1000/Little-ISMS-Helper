<?php

namespace App\Service;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\RiskRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Incident → Risk Feedback Loop Service
 *
 * ISO 27001:2022 Clause 6.1.2 (c): "take into account past experience of security incidents"
 * ISO 27005:2022: Lessons learned from incidents should update risk assessments
 *
 * This service automatically triggers risk re-evaluation when incidents occur,
 * updating likelihood based on real-world evidence of control failures.
 *
 * Workflow Integration:
 * When an incident workflow completes, this service:
 * 1. Identifies related risks (via realizedRisks collection)
 * 2. Triggers risk re-assessment workflow if likelihood exceeds threshold
 * 3. Creates audit trail for compliance documentation
 *
 * Data Reuse Pattern:
 * - Incident.realizedRisks → automatic risk identification
 * - Incident.failedControls → identify control weaknesses
 * - Risk.likelihood update based on incident evidence
 */
class IncidentRiskFeedbackService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskRepository $riskRepository,
        private readonly WorkflowService $workflowService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Process incident feedback to update related risks
     *
     * Called when incident workflow completes (status = 'closed')
     * Triggers risk re-evaluation for all related risks
     *
     * @param Incident $incident The completed incident
     * @param User $user User who closed the incident
     * @return int Number of risks triggered for re-evaluation
     */
    public function processIncidentFeedback(Incident $incident, User $user): int
    {
        // Only process closed incidents
        if ($incident->getStatus() !== 'closed') {
            return 0;
        }

        $triggeredCount = 0;
        $realizedRisks = $incident->getRealizedRisks();

        if ($realizedRisks->isEmpty()) {
            $this->logger->info('Incident has no related risks - no feedback required', [
                'incident_id' => $incident->getId(),
                'incident_number' => $incident->getIncidentNumber(),
            ]);
            return 0;
        }

        $this->logger->info('Processing incident→risk feedback loop', [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
            'related_risks' => $realizedRisks->count(),
            'severity' => $incident->getSeverity(),
        ]);

        foreach ($realizedRisks as $risk) {
            if ($this->shouldTriggerRiskReEvaluation($incident, $risk)) {
                $this->triggerRiskReEvaluation($risk, $incident, $user);
                $triggeredCount++;
            }
        }

        if ($triggeredCount > 0) {
            $this->logger->info('Incident→Risk feedback completed', [
                'incident_id' => $incident->getId(),
                'risks_triggered' => $triggeredCount,
            ]);
        }

        return $triggeredCount;
    }

    /**
     * Check if risk re-evaluation should be triggered
     *
     * Re-evaluation criteria:
     * - High/Critical severity incidents always trigger
     * - Medium severity if control failure detected
     * - Low severity if multiple related incidents
     */
    private function shouldTriggerRiskReEvaluation(Incident $incident, Risk $risk): bool
    {
        // Always trigger for high/critical incidents
        if (in_array($incident->getSeverity(), ['high', 'critical'], true)) {
            return true;
        }

        // Medium severity: trigger if controls failed
        if ($incident->getSeverity() === 'medium') {
            $failedControls = $incident->getFailedControls();
            if (!$failedControls->isEmpty()) {
                return true;
            }
        }

        // Low severity: check if multiple related incidents
        if ($incident->getSeverity() === 'low') {
            $relatedIncidentCount = $this->countRelatedIncidents($risk);
            return $relatedIncidentCount >= 3; // Threshold: 3+ low-severity incidents
        }

        return false;
    }

    /**
     * Trigger risk re-evaluation workflow
     *
     * Starts a new risk assessment workflow for the risk
     * Records incident as trigger for audit trail
     */
    private function triggerRiskReEvaluation(Risk $risk, Incident $incident, User $user): void
    {
        $this->logger->info('Triggering risk re-evaluation due to incident', [
            'risk_id' => $risk->getId(),
            'risk_title' => $risk->getTitle(),
            'incident_id' => $incident->getId(),
            'incident_severity' => $incident->getSeverity(),
        ]);

        // Add incident reference to risk notes
        $this->addIncidentToRiskNotes($risk, $incident);

        // Check if risk already has an active workflow
        $existingWorkflow = $this->workflowService->getWorkflowInstance('Risk', $risk->getId());

        if ($existingWorkflow && $existingWorkflow->getStatus() === 'in_progress') {
            $this->logger->info('Risk already has active workflow - not starting new one', [
                'risk_id' => $risk->getId(),
                'workflow_id' => $existingWorkflow->getId(),
            ]);
            return;
        }

        // Start new risk assessment workflow
        try {
            $workflow = $this->workflowService->startWorkflow(
                entityType: 'Risk',
                entityId: $risk->getId(),
                initiatedBy: $user,
                triggerReason: sprintf(
                    'Automatic re-evaluation triggered by incident %s (severity: %s)',
                    $incident->getIncidentNumber(),
                    $incident->getSeverity()
                )
            );

            if ($workflow) {
                $this->logger->info('Risk re-evaluation workflow started', [
                    'risk_id' => $risk->getId(),
                    'workflow_id' => $workflow->getId(),
                    'trigger_incident' => $incident->getIncidentNumber(),
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to start risk re-evaluation workflow', [
                'risk_id' => $risk->getId(),
                'incident_id' => $incident->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add incident reference to risk notes for audit trail
     */
    private function addIncidentToRiskNotes(Risk $risk, Incident $incident): void
    {
        $existingNotes = $risk->getNotes() ?? '';
        $incidentNote = sprintf(
            "\n\n[%s] Incident-triggered re-evaluation: %s (ID: %d, Severity: %s)\n" .
            "Incident occurred: %s\n" .
            "Description: %s",
            (new DateTimeImmutable())->format('Y-m-d H:i'),
            $incident->getIncidentNumber(),
            $incident->getId(),
            $incident->getSeverity(),
            $incident->getDetectedAt()?->format('Y-m-d H:i') ?? 'Unknown',
            substr($incident->getDescription(), 0, 200) // First 200 chars
        );

        $risk->setNotes($existingNotes . $incidentNote);
        $this->entityManager->flush();
    }

    /**
     * Count related incidents for a risk
     *
     * Used to determine if low-severity incidents should trigger re-evaluation
     */
    private function countRelatedIncidents(Risk $risk): int
    {
        // Get all incidents that realized this risk
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(i.id)')
            ->from('App\Entity\Incident', 'i')
            ->join('i.realizedRisks', 'r')
            ->where('r.id = :risk_id')
            ->andWhere('i.status = :status')
            ->setParameter('risk_id', $risk->getId())
            ->setParameter('status', 'closed');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get recommended likelihood increase based on incident severity
     *
     * ISO 27005:2022 guidance: Real incidents indicate higher likelihood
     * than initially assessed
     */
    public function getRecommendedLikelihoodIncrease(Incident $incident): int
    {
        return match ($incident->getSeverity()) {
            'critical' => 2, // Increase likelihood by 2 levels
            'high' => 2,
            'medium' => 1,
            'low' => 0,  // No automatic increase for low severity
            default => 0,
        };
    }
}
