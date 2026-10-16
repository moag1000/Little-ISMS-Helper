<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CorrectiveAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CorrectiveAction Service — persistence + business logic for CorrectiveAction entities.
 *
 * ISO 27001 Clause 10.1. The controller keeps routing, form-handling, flash messages,
 * redirects and tenant-check helpers. All EntityManager writes + audit-log calls live here
 * (audit gate: em_writes_in_controller).
 */
final class CorrectiveActionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Persist a new CorrectiveAction and write audit-log.
     */
    public function create(CorrectiveAction $action): void
    {
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            'CorrectiveAction',
            $action->getId(),
            [
                'title'          => $action->getTitle(),
                'status'         => $action->getStatus(),
                'previousCapaId' => $action->getPreviousCapa()?->getId(),
            ],
            'CorrectiveAction created'
        );
    }

    /**
     * Flush changes to an existing CorrectiveAction and write audit-log.
     */
    public function update(CorrectiveAction $action): void
    {
        $this->entityManager->flush();

        $this->auditLogger->logUpdate(
            'CorrectiveAction',
            $action->getId(),
            [],
            ['status' => $action->getStatus()],
            'CorrectiveAction updated'
        );
    }

    /**
     * Remove a CorrectiveAction and flush, preceded by audit-log (log before remove so
     * the ID is still available).
     */
    public function delete(CorrectiveAction $action): void
    {
        $this->auditLogger->logDelete(
            'CorrectiveAction',
            $action->getId(),
            ['title' => $action->getTitle()],
            'CorrectiveAction deleted'
        );
        $this->entityManager->remove($action);
        $this->entityManager->flush();
    }
}
