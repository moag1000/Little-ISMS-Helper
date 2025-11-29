<?php

namespace App\Service;

use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\User;
use App\Entity\RiskAppetite;
use App\Repository\RiskAppetiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use DateTimeImmutable;

/**
 * Workflow Auto-Progression Service
 *
 * Automatically advances workflow steps based on entity field completion.
 * This enables event-driven workflows where completing specific fields
 * (e.g., DPO filling out DataBreach severity) automatically approves the step.
 *
 * Workflow Step Metadata Structure:
 * {
 *   "autoProgressConditions": {
 *     "type": "field_completion",
 *     "entity": "DataBreach",
 *     "fields": ["severity", "affectedDataSubjectsCount"],
 *     "condition": "severity >= high"  // Optional additional condition
 *   }
 * }
 *
 * Example Usage:
 * - DPO saves DataBreach with severity, affectedDataSubjectsCount filled
 * - Auto-progression service checks active workflow for DataBreach
 * - Current step requires ["severity", "affectedDataSubjectsCount"]
 * - All fields are filled → step auto-approved → move to next step
 *
 * This follows the regulatory requirement that workflows should progress
 * naturally with user activities in modules (nicht-invasiv).
 */
class WorkflowAutoProgressionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly WorkflowService $workflowService,
        private readonly LoggerInterface $logger,
        private readonly RiskAppetiteRepository $riskAppetiteRepository
    ) {}

    /**
     * Check and auto-progress workflow for an entity
     *
     * Called after entity save/update to check if workflow step can auto-progress
     *
     * @param object $entity The entity that was updated (e.g., DataBreach, Incident)
     * @param User $user The user who made the update
     * @return bool True if workflow was auto-progressed, false otherwise
     */
    public function checkAndProgressWorkflow(object $entity, User $user): bool
    {
        // Get entity type and ID
        $entityType = $this->getEntityShortName($entity);
        $entityId = $this->propertyAccessor->getValue($entity, 'id');

        if (!$entityId) {
            return false; // Entity not persisted yet
        }

        // Get active workflow instance for this entity
        $workflowInstance = $this->workflowService->getWorkflowInstance($entityType, $entityId);

        if (!$workflowInstance || $workflowInstance->getStatus() !== 'in_progress') {
            return false; // No active workflow
        }

        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep) {
            return false; // No current step
        }

        // Check if step can auto-progress
        if (!$this->canAutoProgress($currentStep, $entity)) {
            return false;
        }

        // Auto-approve the step
        $this->autoApproveStep($workflowInstance, $currentStep, $user, $entity);

        $this->logger->info('Workflow auto-progressed', [
            'workflow_instance_id' => $workflowInstance->getId(),
            'step_id' => $currentStep->getId(),
            'step_name' => $currentStep->getName(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $user->getId(),
        ]);

        return true;
    }

    /**
     * Check if a workflow step can auto-progress based on entity state
     */
    private function canAutoProgress(WorkflowStep $step, object $entity): bool
    {
        $metadata = $step->getMetadata();
        if (!$metadata || !isset($metadata['autoProgressConditions'])) {
            return false; // No auto-progression configured
        }

        $conditions = $metadata['autoProgressConditions'];

        // Check auto-progression type
        if (!isset($conditions['type'])) {
            return false;
        }

        return match ($conditions['type']) {
            'field_completion' => $this->checkFieldCompletion($conditions, $entity),
            'auto' => $this->checkAutoCondition($conditions, $entity),
            'risk_appetite' => $this->checkRiskAppetite($conditions, $entity),
            default => false,
        };
    }

    /**
     * Check if required fields are completed
     */
    private function checkFieldCompletion(array $conditions, object $entity): bool
    {
        if (!isset($conditions['fields']) || !is_array($conditions['fields'])) {
            return false;
        }

        // Check if entity type matches
        if (isset($conditions['entity'])) {
            $expectedType = $conditions['entity'];
            $actualType = $this->getEntityShortName($entity);
            if ($expectedType !== $actualType) {
                return false; // Entity type mismatch
            }
        }

        // Check all required fields are filled
        foreach ($conditions['fields'] as $fieldName) {
            try {
                $value = $this->propertyAccessor->getValue($entity, $fieldName);

                // Check if field is empty (null, empty string, empty array)
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    return false; // Field not filled
                }
            } catch (\Exception $e) {
                $this->logger->warning('Could not access field for auto-progression', [
                    'entity' => $this->getEntityShortName($entity),
                    'field' => $fieldName,
                    'error' => $e->getMessage(),
                ]);
                return false; // Field doesn't exist
            }
        }

        // Check optional additional condition (e.g., "severity >= high")
        if (isset($conditions['condition'])) {
            return $this->evaluateCondition($conditions['condition'], $entity);
        }

        return true; // All fields filled
    }

    /**
     * Check auto-progression condition (for notification steps)
     */
    private function checkAutoCondition(array $conditions, object $entity): bool
    {
        // Auto-type steps progress immediately (notification steps)
        // Optional condition can gate the progression
        if (isset($conditions['condition'])) {
            return $this->evaluateCondition($conditions['condition'], $entity);
        }

        return true; // Auto-progress unconditionally
    }

    /**
     * Check Risk Appetite for automatic approval
     *
     * ISO 27005:2022 - Risk Treatment Auto-Approval
     * Automatically approve risk treatment if residual risk is within risk appetite
     *
     * Metadata example:
     * {
     *   "type": "risk_appetite",
     *   "entity": "Risk",
     *   "riskScoreField": "residualRisk",
     *   "categoryField": "category"  // Optional - for category-specific appetite
     * }
     */
    private function checkRiskAppetite(array $conditions, object $entity): bool
    {
        // Only applicable for Risk entities
        if (!isset($conditions['entity']) || $conditions['entity'] !== 'Risk') {
            return false;
        }

        $entityType = $this->getEntityShortName($entity);
        if ($entityType !== 'Risk') {
            return false; // Entity type mismatch
        }

        // Get risk score field (default: residualRisk)
        $riskScoreField = $conditions['riskScoreField'] ?? 'residualRisk';

        try {
            $riskScore = $this->propertyAccessor->getValue($entity, $riskScoreField);

            if ($riskScore === null) {
                return false; // Risk not yet assessed
            }

            // Get risk category (if specified) for category-specific appetite
            $category = null;
            if (isset($conditions['categoryField'])) {
                $category = $this->propertyAccessor->getValue($entity, $conditions['categoryField']);
            }

            // Get applicable risk appetite
            $riskAppetite = $this->getApplicableRiskAppetite($entity, $category);

            if (!$riskAppetite) {
                $this->logger->warning('No active risk appetite found - cannot auto-approve based on appetite', [
                    'entity' => $entityType,
                    'risk_score' => $riskScore,
                    'category' => $category,
                ]);
                return false; // No appetite defined, cannot auto-approve
            }

            // Check if risk is within appetite
            $isAcceptable = $riskAppetite->isRiskAcceptable($riskScore);

            $this->logger->info('Risk appetite check for workflow auto-progression', [
                'entity' => $entityType,
                'risk_score' => $riskScore,
                'max_acceptable' => $riskAppetite->getMaxAcceptableRisk(),
                'category' => $category,
                'is_acceptable' => $isAcceptable,
            ]);

            return $isAcceptable;
        } catch (\Exception $e) {
            $this->logger->error('Error checking risk appetite for workflow auto-progression', [
                'entity' => $entityType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get applicable risk appetite for an entity
     *
     * Priority:
     * 1. Category-specific appetite (if category provided)
     * 2. Global appetite (category = null)
     */
    private function getApplicableRiskAppetite(object $entity, ?string $category): ?RiskAppetite
    {
        // Get tenant from entity
        $tenant = null;
        try {
            $tenant = $this->propertyAccessor->getValue($entity, 'tenant');
        } catch (\Exception $e) {
            // Entity may not have tenant field
        }

        // Try category-specific appetite first
        if ($category !== null && $tenant !== null) {
            $categoryAppetite = $this->riskAppetiteRepository->findOneBy([
                'tenant' => $tenant,
                'category' => $category,
                'isActive' => true,
            ]);

            if ($categoryAppetite) {
                return $categoryAppetite;
            }
        }

        // Fallback to global appetite
        if ($tenant !== null) {
            return $this->riskAppetiteRepository->findOneBy([
                'tenant' => $tenant,
                'category' => null, // Global appetite
                'isActive' => true,
            ]);
        }

        return null;
    }

    /**
     * Evaluate a condition expression
     * Supports:
     * - Simple: "field >= value", "field = value", "field != value"
     * - AND/OR: "(severity >= high AND affectedCount > 100) OR notificationRequired = true"
     */
    private function evaluateCondition(string $condition, object $entity): bool
    {
        // Check for AND/OR operators (advanced logic)
        if (str_contains($condition, ' AND ') || str_contains($condition, ' OR ')) {
            return $this->evaluateComplexCondition($condition, $entity);
        }

        // Simple condition evaluation
        // Parse simple conditions like "severity >= high" or "notificationRequired = true"
        if (preg_match('/^(\w+)\s*(>=|<=|>|<|=|!=)\s*(.+)$/', $condition, $matches)) {
            $fieldName = $matches[1];
            $operator = $matches[2];
            $expectedValue = trim($matches[3]);

            try {
                $actualValue = $this->propertyAccessor->getValue($entity, $fieldName);

                // Handle boolean values
                if ($expectedValue === 'true') {
                    $expectedValue = true;
                } elseif ($expectedValue === 'false') {
                    $expectedValue = false;
                }

                // Handle numeric comparisons
                if (is_numeric($actualValue) && is_numeric($expectedValue)) {
                    return $this->compareValues($actualValue, $operator, (float)$expectedValue);
                }

                // Handle string comparisons
                return $this->compareValues($actualValue, $operator, $expectedValue);
            } catch (\Exception $e) {
                $this->logger->warning('Could not evaluate condition for auto-progression', [
                    'condition' => $condition,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        return false; // Could not parse condition
    }

    /**
     * Evaluate complex conditions with AND/OR logic
     * Example: "(severity >= high AND affectedCount > 100) OR notificationRequired = true"
     */
    private function evaluateComplexCondition(string $condition, object $entity): bool
    {
        // Remove outer parentheses if present
        $condition = trim($condition);
        if (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            $condition = substr($condition, 1, -1);
        }

        // Split by OR first (lower precedence)
        if (str_contains($condition, ' OR ')) {
            $orParts = explode(' OR ', $condition);
            foreach ($orParts as $orPart) {
                if ($this->evaluateComplexCondition(trim($orPart), $entity)) {
                    return true; // OR: any true → whole expression true
                }
            }
            return false;
        }

        // Split by AND (higher precedence)
        if (str_contains($condition, ' AND ')) {
            $andParts = explode(' AND ', $condition);
            foreach ($andParts as $andPart) {
                if (!$this->evaluateComplexCondition(trim($andPart), $entity)) {
                    return false; // AND: any false → whole expression false
                }
            }
            return true;
        }

        // Remove parentheses and evaluate as simple condition
        $condition = trim($condition, '()');
        return $this->evaluateCondition($condition, $entity);
    }

    /**
     * Compare values based on operator
     */
    private function compareValues($actual, string $operator, $expected): bool
    {
        return match ($operator) {
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '=' => $actual == $expected,
            '!=' => $actual != $expected,
            default => false,
        };
    }

    /**
     * Auto-approve a workflow step
     */
    private function autoApproveStep(
        WorkflowInstance $workflowInstance,
        WorkflowStep $step,
        User $user,
        object $entity
    ): void {
        // Add to approval history
        $workflowInstance->addApprovalHistoryEntry([
            'step_id' => $step->getId(),
            'step_name' => $step->getName(),
            'action' => 'auto_approved',
            'approver_id' => $user->getId(),
            'approver_name' => $user->getFirstName() . ' ' . $user->getLastName(),
            'comments' => 'Step automatically approved based on field completion',
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'auto_progression' => true,
            'trigger_entity' => $this->getEntityShortName($entity),
        ]);

        // Mark step as completed
        $workflowInstance->addCompletedStep($step->getId());

        // Move to next step using WorkflowService
        // We'll use reflection to access the private method temporarily
        $reflection = new \ReflectionClass($this->workflowService);
        $method = $reflection->getMethod('moveToNextStep');
        $method->setAccessible(true);
        $nextStep = $method->invoke($this->workflowService, $workflowInstance);

        // Handle next step (could also auto-progress if notification step)
        if ($nextStep instanceof WorkflowStep) {
            // Check if next step can also auto-progress (e.g., notification steps)
            if ($this->canAutoProgress($nextStep, $entity)) {
                $this->autoApproveStep($workflowInstance, $nextStep, $user, $entity);
            } else {
                // Send assignment notification for next step
                $handleMethod = $reflection->getMethod('handleStepAssignment');
                $handleMethod->setAccessible(true);
                $handleMethod->invoke($this->workflowService, $workflowInstance, $nextStep);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Get entity short name (class name without namespace)
     */
    private function getEntityShortName(object $entity): string
    {
        $className = get_class($entity);

        // Handle Doctrine proxies
        if (str_contains($className, 'Proxies\\__CG__\\')) {
            $className = substr($className, strlen('Proxies\\__CG__\\'));
        }

        return substr($className, strrpos($className, '\\') + 1);
    }
}
