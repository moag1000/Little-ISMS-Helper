<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\User;
use App\Entity\RiskAppetite;
use App\Lifecycle\FieldCompletionAutoTransitionInterface;
use App\Repository\RiskAppetiteRepository;
use App\Service\Notification\SlaDeadlineFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use DateTimeImmutable;

/**
 * @deprecated since v3.8 — use {@see \App\Lifecycle\EventListener\FieldCompletionAutoTransition} listener instead.
 *             Logic has been migrated to the YAML-driven
 *             `lifecycle.auto_transition_rules` config in
 *             `config/packages/lifecycle.yaml`. This class is now a thin
 *             wrapper that delegates to the listener for the field-completion
 *             path and retains the legacy approval-chain path for backward
 *             compatibility with the 14 call-sites that still inject WAPS.
 *             Will be removed in v4.0.
 *
 * Workflow Auto-Progression Service (DEPRECATED WRAPPER)
 *
 * The original role of this class was to automatically advance workflow steps
 * based on entity field completion. That responsibility now lives in
 * FieldCompletionAutoTransition, which fires automatically on Doctrine
 * postUpdate events for entities declared in lifecycle.auto_transition_rules.
 *
 * Callers that still inject this service do NOT need to change for v3.x:
 *   - The checkAndProgressWorkflow() method fires the FieldCompletionAutoTransition
 *     listener via a synthetic PostUpdateEventArgs, producing identical behaviour.
 *   - The legacy approval-chain path (WorkflowStep.metadata.autoProgressConditions)
 *     is preserved for WorkflowInstances not yet migrated to YAML-based config.
 *
 * Migration guide for callers:
 *   1. Remove the WAPS constructor argument.
 *   2. Remove explicit checkAndProgressWorkflow() calls — Doctrine fires the
 *      listener automatically after every flush().
 *   3. If the entity was not yet in lifecycle.auto_transition_rules, add it.
 *
 * @see \App\Lifecycle\EventListener\FieldCompletionAutoTransition
 * @see config/packages/lifecycle.yaml  lifecycle.auto_transition_rules
 */
class WorkflowAutoProgressionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly WorkflowService $workflowService,
        private readonly LoggerInterface $logger,
        private readonly RiskAppetiteRepository $riskAppetiteRepository,
        private readonly FieldCompletionAutoTransitionInterface $fieldCompletionAutoTransition,
        private readonly ?SlaDeadlineFactory $slaDeadlineFactory = null,
    ) {}

    /**
     * Check and auto-progress workflow for an entity.
     *
     * @deprecated since v3.8 — Doctrine fires {@see \App\Lifecycle\EventListener\FieldCompletionAutoTransition}
     *             automatically on postUpdate; explicit calls are no longer needed.
     *
     * @param object $entity The entity that was updated (e.g., DataBreach, Incident)
     * @param User   $user   The user who made the update
     * @return bool True if auto-progression was triggered, false otherwise
     */
    public function checkAndProgressWorkflow(object $entity, User $user): bool
    {
        // ── Step 1: delegate to the new YAML-driven listener (side-effect) ───
        // Fire the FieldCompletionAutoTransition listener synthetically so all
        // lifecycle.auto_transition_rules entries are evaluated. This covers
        // entities that have been migrated to the YAML-based config.
        // The listener is best-effort and does not report whether it transitioned;
        // the return value of THIS method is determined by the legacy path only,
        // preserving backward-compat for all 14 call-sites.
        try {
            $syntheticArgs = new PostUpdateEventArgs($entity, $this->entityManager);
            $this->fieldCompletionAutoTransition->postUpdate($syntheticArgs);
        } catch (\Throwable $e) {
            $this->logger->warning('[WAPS] FieldCompletionAutoTransition delegate threw unexpectedly', [
                'entity' => $this->getEntityShortName($entity),
                'error'  => $e->getMessage(),
            ]);
        }

        // ── Step 2: legacy approval-chain path ───────────────────────────────
        // For WorkflowInstances whose steps still carry autoProgressConditions
        // metadata (not yet migrated to YAML), retain the original behaviour.
        // Return value reflects whether the legacy chain progressed.
        return $this->legacyCheckAndProgress($entity, $user);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Legacy approval-chain code — preserved for backward compat.
    // This section mirrors the original implementation and will be removed in v4.0.
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Legacy field-completion + risk_appetite check against WorkflowStep metadata.
     *
     * @deprecated since v3.8 — internal use only; will be removed with the class.
     */
    private function legacyCheckAndProgress(object $entity, User $user): bool
    {
        $entityType = $this->getEntityShortName($entity);
        $entityId   = $this->propertyAccessor->getValue($entity, 'id');

        if (!$entityId) {
            return false;
        }

        $workflowInstance = $this->workflowService->getWorkflowInstance($entityType, $entityId);

        if (!$workflowInstance || $workflowInstance->getStatus() !== 'in_progress') {
            return false;
        }

        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep) {
            return false;
        }

        if (!$this->canAutoProgress($currentStep, $entity)) {
            return false;
        }

        $this->autoApproveStep($workflowInstance, $currentStep, $user, $entity);

        $this->maybeSpawnSlaMonitor($entity, $currentStep);

        $this->logger->info('[WAPS legacy] Workflow auto-progressed via step metadata', [
            'workflow_instance_id' => $workflowInstance->getId(),
            'step_id'              => $currentStep->getId(),
            'step_name'            => $currentStep->getName(),
            'entity_type'          => $entityType,
            'entity_id'            => $entityId,
            'user_id'              => $user->getId(),
        ]);

        return true;
    }

    private function canAutoProgress(WorkflowStep $step, object $entity): bool
    {
        $metadata = $step->getMetadata();
        if (!$metadata || !isset($metadata['autoProgressConditions'])) {
            return false;
        }

        $conditions = $metadata['autoProgressConditions'];

        if (!isset($conditions['type'])) {
            return false;
        }

        return match ($conditions['type']) {
            'field_completion' => $this->checkFieldCompletion($conditions, $entity),
            'auto'             => $this->checkAutoCondition($conditions, $entity),
            'risk_appetite'    => $this->checkRiskAppetite($conditions, $entity),
            default            => false,
        };
    }

    private function checkFieldCompletion(array $conditions, object $entity): bool
    {
        if (!isset($conditions['fields']) || !is_array($conditions['fields'])) {
            return false;
        }

        if (isset($conditions['entity'])) {
            if ($conditions['entity'] !== $this->getEntityShortName($entity)) {
                return false;
            }
        }

        foreach ($conditions['fields'] as $fieldName) {
            try {
                $value = $this->propertyAccessor->getValue($entity, $fieldName);
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    return false;
                }
            } catch (Exception $e) {
                $this->logger->warning('[WAPS legacy] Could not access field', [
                    'entity' => $this->getEntityShortName($entity),
                    'field'  => $fieldName,
                    'error'  => $e->getMessage(),
                ]);
                return false;
            }
        }

        if (isset($conditions['condition'])) {
            return $this->evaluateCondition($conditions['condition'], $entity);
        }

        return true;
    }

    private function checkAutoCondition(array $conditions, object $entity): bool
    {
        if (isset($conditions['condition'])) {
            return $this->evaluateCondition($conditions['condition'], $entity);
        }
        return true;
    }

    private function checkRiskAppetite(array $conditions, object $entity): bool
    {
        if (!isset($conditions['entity']) || $conditions['entity'] !== 'Risk') {
            return false;
        }

        if ($this->getEntityShortName($entity) !== 'Risk') {
            return false;
        }

        $riskScoreField = $conditions['riskScoreField'] ?? 'residualRisk';

        try {
            $riskScore = $this->propertyAccessor->getValue($entity, $riskScoreField);

            if ($riskScore === null) {
                return false;
            }

            $category = null;
            if (isset($conditions['categoryField'])) {
                $category = $this->propertyAccessor->getValue($entity, $conditions['categoryField']);
            }

            $riskAppetite = $this->getApplicableRiskAppetite($entity, $category);

            if (!$riskAppetite) {
                $this->logger->warning('[WAPS legacy] No active risk appetite — cannot auto-approve', [
                    'entity'     => $this->getEntityShortName($entity),
                    'risk_score' => $riskScore,
                    'category'   => $category,
                ]);
                return false;
            }

            $isAcceptable = $riskAppetite->isRiskAcceptable($riskScore);

            $this->logger->info('[WAPS legacy] Risk appetite check', [
                'entity'         => $this->getEntityShortName($entity),
                'risk_score'     => $riskScore,
                'max_acceptable' => $riskAppetite->getMaxAcceptableRisk(),
                'category'       => $category,
                'is_acceptable'  => $isAcceptable,
            ]);

            return $isAcceptable;
        } catch (Exception $e) {
            $this->logger->error('[WAPS legacy] Error in risk appetite check', [
                'entity' => $this->getEntityShortName($entity),
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getApplicableRiskAppetite(object $entity, ?string $category): ?RiskAppetite
    {
        $tenant = null;
        try {
            $tenant = $this->propertyAccessor->getValue($entity, 'tenant');
        } catch (Exception) {
            // Entity may not have tenant field
        }

        if ($category !== null && $tenant !== null) {
            $categoryAppetite = $this->riskAppetiteRepository->findOneBy([
                'tenant'   => $tenant,
                'category' => $category,
                'isActive' => true,
            ]);
            if ($categoryAppetite) {
                return $categoryAppetite;
            }
        }

        if ($tenant !== null) {
            return $this->riskAppetiteRepository->findOneBy([
                'tenant'   => $tenant,
                'category' => null,
                'isActive' => true,
            ]);
        }

        return null;
    }

    private function evaluateCondition(string $condition, object $entity): bool
    {
        if (str_contains($condition, ' AND ') || str_contains($condition, ' OR ')) {
            return $this->evaluateComplexCondition($condition, $entity);
        }

        if (preg_match('/^(\w+)\s*(>=|<=|>|<|=|!=)\s*(.+)$/', $condition, $matches)) {
            $fieldName     = $matches[1];
            $operator      = $matches[2];
            $expectedValue = trim($matches[3]);

            try {
                $actualValue = $this->propertyAccessor->getValue($entity, $fieldName);

                if ($expectedValue === 'null') {
                    return $operator === '!=' ? $actualValue !== null : $actualValue === null;
                }

                if ($expectedValue === 'true') {
                    $expectedValue = true;
                } elseif ($expectedValue === 'false') {
                    $expectedValue = false;
                }

                if (is_numeric($actualValue) && is_numeric($expectedValue)) {
                    return $this->compareValues($actualValue, $operator, (float) $expectedValue);
                }

                return $this->compareValues($actualValue, $operator, $expectedValue);
            } catch (Exception $e) {
                $this->logger->warning('[WAPS legacy] Could not evaluate condition', [
                    'condition' => $condition,
                    'error'     => $e->getMessage(),
                ]);
                return false;
            }
        }

        return false;
    }

    private function evaluateComplexCondition(string $condition, object $entity): bool
    {
        $condition = trim($condition);
        if (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            $condition = substr($condition, 1, -1);
        }

        if (str_contains($condition, ' OR ')) {
            $orParts = explode(' OR ', $condition);
            foreach ($orParts as $orPart) {
                if ($this->evaluateComplexCondition(trim($orPart), $entity)) {
                    return true;
                }
            }
            return false;
        }

        if (str_contains($condition, ' AND ')) {
            $andParts = explode(' AND ', $condition);
            foreach ($andParts as $andPart) {
                if (!$this->evaluateComplexCondition(trim($andPart), $entity)) {
                    return false;
                }
            }
            return true;
        }

        $condition = trim($condition, '()');
        return $this->evaluateCondition($condition, $entity);
    }

    private function compareValues(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            '>'  => $actual > $expected,
            '<'  => $actual < $expected,
            '='  => $actual == $expected,
            '!=' => $actual != $expected,
            default => false,
        };
    }

    private function autoApproveStep(
        WorkflowInstance $workflowInstance,
        WorkflowStep $step,
        User $user,
        object $entity,
        int $depth = 0,
    ): void {
        if ($depth >= 20) {
            $this->logger->warning('[WAPS legacy] Auto-progression recursion limit reached', [
                'workflow_instance_id' => $workflowInstance->getId(),
                'step_id'              => $step->getId(),
                'depth'                => $depth,
            ]);
            return;
        }

        $workflowInstance->addApprovalHistoryEntry([
            'step_id'          => $step->getId(),
            'step_name'        => $step->getName(),
            'action'           => 'auto_approved',
            'approver_id'      => $user->getId(),
            'approver_name'    => $user->getFirstName() . ' ' . $user->getLastName(),
            'comments'         => 'Step automatically approved based on field completion',
            'timestamp'        => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'auto_progression' => true,
            'trigger_entity'   => $this->getEntityShortName($entity),
        ]);

        $workflowInstance->addCompletedStep($step->getId());

        $nextStep = $this->workflowService->moveToNextStep($workflowInstance);

        if ($nextStep instanceof WorkflowStep) {
            if ($this->canAutoProgress($nextStep, $entity)) {
                $this->autoApproveStep($workflowInstance, $nextStep, $user, $entity, $depth + 1);
            } else {
                $this->workflowService->handleStepAssignment($workflowInstance, $nextStep);
            }
        } else {
            $this->handleWorkflowCompletion($workflowInstance, $entity, $user);
        }

        $this->entityManager->flush();
    }

    private function handleWorkflowCompletion(WorkflowInstance $workflowInstance, object $entity, User $user): void
    {
        $entityType = $this->getEntityShortName($entity);

        $this->logger->info('[WAPS legacy] Workflow completed — checking for feedback loops', [
            'workflow_id' => $workflowInstance->getId(),
            'entity_type' => $entityType,
            'entity_id'   => $this->propertyAccessor->getValue($entity, 'id'),
        ]);

        if ($entityType === 'Incident') {
            $this->triggerIncidentRiskFeedback($entity, $user);
        }
    }

    private function triggerIncidentRiskFeedback(object $incident, User $user): void
    {
        try {
            // BACKLOG: Dispatch an IncidentClosedEvent from IncidentController when
            // status changes to 'closed', and handle feedback in a dedicated EventSubscriber.
            // See original implementation comment for rationale (circular-dependency avoidance).
            $this->logger->info('[WAPS legacy] Incident workflow completed — risk feedback deferred to event', [
                'incident_id'     => $this->propertyAccessor->getValue($incident, 'id'),
                'incident_number' => $this->propertyAccessor->getValue($incident, 'incidentNumber'),
                'status'          => $this->propertyAccessor->getValue($incident, 'status'),
            ]);
        } catch (Exception $e) {
            $this->logger->error('[WAPS legacy] Error in incident→risk feedback', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function maybeSpawnSlaMonitor(object $entity, WorkflowStep $step): void
    {
        if ($this->slaDeadlineFactory === null) {
            return;
        }

        $metadata = $step->getMetadata();
        if (!isset($metadata['slaDeadline'])) {
            return;
        }

        try {
            $entityType = $this->getEntityShortName($entity);

            match ($entityType) {
                'DataBreach'   => $entity instanceof DataBreach
                    ? $this->slaDeadlineFactory->createForDataBreach($entity)
                    : null,
                'Incident'     => $entity instanceof Incident
                    ? $this->slaDeadlineFactory->createForIncident(
                        $entity,
                        $entity->getSeverity()?->value ?? 'low',
                    )
                    : null,
                'AuditFinding' => $entity instanceof AuditFinding
                    ? $this->slaDeadlineFactory->createForCorrectiveAction($entity)
                    : null,
                default        => null,
            };

            $this->logger->info('[WAPS legacy] SLA deadline monitor spawned on workflow step transition', [
                'entity_type' => $entityType,
                'step'        => $step->getName(),
            ]);
        } catch (Exception $e) {
            $this->logger->error('[WAPS legacy] Failed to spawn SLA deadline monitor', [
                'error'       => $e->getMessage(),
                'entity_type' => $this->getEntityShortName($entity),
                'step'        => $step->getName(),
            ]);
        }
    }

    private function getEntityShortName(object $entity): string
    {
        $className = get_class($entity);

        if (str_contains($className, 'Proxies\\__CG__\\')) {
            $className = substr($className, strlen('Proxies\\__CG__\\'));
        }

        return substr($className, strrpos($className, '\\') + 1);
    }
}
