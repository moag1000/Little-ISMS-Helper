<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EvidenceReverificationTask;
use App\Entity\User;
use App\Repository\EvidenceReverificationTaskRepository;
use App\Service\AuditLogger;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F4 Evidence-Versioning — Reviewer Queue controller.
 *
 * Routes (no /{_locale}/ prefix — added by config/routes.yaml wrapper):
 *   GET  /evidence-reverification             — queue index
 *   GET  /evidence-reverification/{id}      — task show
 *   POST /evidence-reverification/{id}/complete — mark completed
 *   POST /evidence-reverification/{id}/skip     — mark skipped
 *
 * Document is a core feature (no module key) — no module gate required.
 */
#[IsGranted('ROLE_USER')]
class EvidenceReverificationController extends AbstractController
{
    public function __construct(
        private readonly EvidenceReverificationTaskRepository $taskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EvidenceCascadeInvalidationService $cascadeService,
        private readonly AuditLogger $auditLogger,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {}

    /**
     * Reviewer queue — all open tasks for the current tenant.
     */
    #[Route('/evidence-reverification', name: 'app_evidence_reverification_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $user = $this->security->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;

        if ($tenant === null) {
            throw $this->createAccessDeniedException();
        }

        $filter = $request->query->get('filter', 'open'); // open | all | mine | overdue
        $tasks = match ($filter) {
            'all' => $this->taskRepository->findAllByTenant($tenant),
            'mine' => $user instanceof User
                ? $this->taskRepository->findOpenByAssignee($user, $tenant)
                : [],
            'overdue' => $this->taskRepository->findOverdueByTenant($tenant),
            default => $this->taskRepository->findOpenByTenant($tenant),
        };

        $openCount = $this->taskRepository->countOpenByTenant($tenant);

        return $this->render('evidence_reverification/index.html.twig', [
            'tasks' => $tasks,
            'filter' => $filter,
            'open_count' => $openCount,
        ]);
    }

    /**
     * Task detail / show page.
     */
    #[Route('/evidence-reverification/{id}', name: 'app_evidence_reverification_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(EvidenceReverificationTask $task): Response
    {
        $this->denyAccessUnlessGranted('view', $task);

        return $this->render('evidence_reverification/show.html.twig', [
            'task' => $task,
        ]);
    }

    /**
     * Mark a task as completed and reset evidenceOutdated on the linked entity.
     */
    #[Route('/evidence-reverification/{id}/complete', name: 'app_evidence_reverification_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function complete(EvidenceReverificationTask $task, Request $request): Response
    {
        $this->denyAccessUnlessGranted('complete', $task);

        if (!$this->isCsrfTokenValid('revtask_complete_' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('evidence_reverification.error.invalid_token', [], 'document'));
            return $this->redirectToRoute('app_evidence_reverification_show', ['id' => $task->getId()]);
        }

        $notes = (string) $request->request->get('notes', '');
        $user = $this->security->getUser();
        $userEntity = $user instanceof User ? $user : null;

        $task->setStatus(EvidenceReverificationTask::STATUS_COMPLETED);
        $task->setCompletedAt(new DateTimeImmutable());
        $task->setNotes($notes !== '' ? $notes : null);

        // Reset evidenceOutdated on linked entity
        if ($task->getControl() !== null) {
            $this->cascadeService->markControlReverified($task->getControl(), $userEntity);
        }
        if ($task->getComplianceFulfillment() !== null) {
            $this->cascadeService->markFulfillmentReverified($task->getComplianceFulfillment(), $userEntity);
        }

        $this->entityManager->flush();

        // Audit event
        $this->auditLogger->logCustom(
            action: 'update',
            entityType: 'evidence_reverification.task',
            entityId: $task->getId(),
            oldValues: ['status' => 'in_progress'],
            newValues: ['status' => EvidenceReverificationTask::STATUS_COMPLETED, 'notes' => $notes],
            description: 'evidence_reverification.task_completed: task#' . $task->getId(),
        );

        $this->addFlash('success', $this->translator->trans('evidence_reverification.success.completed', [], 'document'));
        return $this->redirectToRoute('app_evidence_reverification_index');
    }

    /**
     * Mark a task as skipped (reviewer decides re-verification not needed).
     */
    #[Route('/evidence-reverification/{id}/skip', name: 'app_evidence_reverification_skip', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function skip(EvidenceReverificationTask $task, Request $request): Response
    {
        $this->denyAccessUnlessGranted('skip', $task);

        if (!$this->isCsrfTokenValid('revtask_skip_' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('evidence_reverification.error.invalid_token', [], 'document'));
            return $this->redirectToRoute('app_evidence_reverification_show', ['id' => $task->getId()]);
        }

        $notes = (string) $request->request->get('notes', '');

        $task->setStatus(EvidenceReverificationTask::STATUS_SKIPPED);
        $task->setCompletedAt(new DateTimeImmutable());
        $task->setNotes($notes !== '' ? $notes : null);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'update',
            entityType: 'evidence_reverification.task',
            entityId: $task->getId(),
            oldValues: ['status' => 'pending'],
            newValues: ['status' => EvidenceReverificationTask::STATUS_SKIPPED, 'notes' => $notes],
            description: 'evidence_reverification.task_skipped: task#' . $task->getId(),
        );

        $this->addFlash('info', $this->translator->trans('evidence_reverification.success.skipped', [], 'document'));
        return $this->redirectToRoute('app_evidence_reverification_index');
    }
}
