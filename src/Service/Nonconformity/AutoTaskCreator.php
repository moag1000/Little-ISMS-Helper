<?php

declare(strict_types=1);

namespace App\Service\Nonconformity;

use App\Entity\AuditFinding;
use App\Entity\ComplianceRequirement;
use App\Entity\CorrectiveAction;
use App\Enum\CorrectiveActionStatus;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F15.2 — AutoTaskCreator
 *
 * When an AuditFinding is linked to ComplianceRequirements, this service
 * automatically creates CorrectiveAction tasks for each linked requirement
 * that does not yet have a corresponding corrective action.
 *
 * Task attributes:
 *  - Type:    corrective
 *  - Status:  planned
 *  - DueDate: +30 days from now (ISO 27001 Cl. 10.1 default SLA)
 *  - Title:   derived from finding + requirement
 *
 * All auto-created tasks are logged via AuditLogger (ISO 27001 Cl. 7.5.3).
 */
final class AutoTaskCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create CorrectiveAction tasks for newly linked ComplianceRequirements.
     * Skips requirements that already have a task for this finding.
     *
     * @return CorrectiveAction[] Created actions
     */
    public function createTasksForLinkedRequirements(AuditFinding $finding): array
    {
        $created = [];
        $dueDate = new DateTimeImmutable('+30 days');

        foreach ($finding->getLinkedRequirements() as $requirement) {
            if ($this->hasExistingTask($finding, $requirement)) {
                continue;
            }

            $action = $this->buildCorrectiveAction($finding, $requirement, $dueDate);
            $this->entityManager->persist($action);
            $created[] = $action;

            $this->auditLogger->logCustom(
                'create',
                'CorrectiveAction',
                null,
                null,
                null,
                sprintf(
                    'Auto-task created for AuditFinding #%d linked to ComplianceRequirement "%s" (%s)',
                    $finding->getId() ?? 0,
                    $requirement->getRequirementId() ?? '—',
                    $requirement->getTitle() ?? '—',
                ),
            );
        }

        if (!empty($created)) {
            $this->entityManager->flush();
        }

        return $created;
    }

    private function buildCorrectiveAction(
        AuditFinding $finding,
        ComplianceRequirement $requirement,
        DateTimeImmutable $dueDate,
    ): CorrectiveAction {
        $action = new CorrectiveAction();
        $action->setTenant($finding->getTenant());
        $action->setFinding($finding);
        $action->setActionType(CorrectiveAction::ACTION_TYPE_CORRECTIVE);
        $action->setStatus(CorrectiveActionStatus::Planned); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist CorrectiveAction; 'planned' is the corrective_action_lifecycle initial_marking)
        $action->setTitle(sprintf(
            '[Auto] %s — %s (%s)',
            $finding->getTitle() ?? 'Audit Finding',
            $requirement->getTitle() ?? 'Compliance Requirement',
            $requirement->getRequirementId() ?? 'N/A',
        ));
        $action->setDescription(sprintf(
            "Automatisch erstellte Korrekturmaßnahme.\n\n" .
            "Audit Finding: %s (Nr. %s)\n" .
            "Norm-Anforderung: %s — %s\n\n" .
            "Bitte Maßnahmen ergänzen und Wirksamkeit dokumentieren (ISO 27001 Cl. 10.1).",
            $finding->getTitle() ?? '—',
            $finding->getFindingNumber() ?? '—',
            $requirement->getRequirementId() ?? '—',
            $requirement->getTitle() ?? '—',
        ));
        $action->setPlannedCompletionDate($dueDate);

        return $action;
    }

    /**
     * Check whether a CorrectiveAction already exists for this finding+requirement
     * combination (to prevent duplicate auto-tasks on re-save).
     */
    private function hasExistingTask(AuditFinding $finding, ComplianceRequirement $requirement): bool
    {
        $requirementTitle = $requirement->getTitle() ?? '';
        $searchTitle = sprintf('[Auto] %s', $finding->getTitle() ?? '');

        foreach ($finding->getCorrectiveActions() as $action) {
            if (str_contains($action->getTitle() ?? '', $searchTitle)
                && str_contains($action->getTitle() ?? '', $requirementTitle)) {
                return true;
            }
        }
        return false;
    }
}
