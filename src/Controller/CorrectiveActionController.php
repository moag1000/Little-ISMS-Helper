<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Form\CorrectiveActionType;
use App\Repository\CommentRepository;
use App\Repository\CorrectiveActionRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * H-01: CRUD for Corrective Actions (ISO 27001 Clause 10.1).
 */
#[IsGranted('ROLE_USER')]
#[Route('/corrective-action', name: 'app_corrective_action_')]
class CorrectiveActionController extends AbstractController
{
    public function __construct(
        private readonly CorrectiveActionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ?CommentRepository $commentRepository = null,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $criteria = $tenant instanceof Tenant ? ['tenant' => $tenant] : [];
        $actions = $this->repository->findBy($criteria, ['createdAt' => 'DESC']);
        $overdue = $tenant instanceof Tenant ? $this->repository->findOverdue($tenant) : [];

        return $this->render('corrective_action/index.html.twig', [
            'actions' => $actions,
            'overdue_count' => count($overdue),
        ]);
    }

    #[Route('/new/{findingId}', name: 'new', methods: ['GET', 'POST'], requirements: ['findingId' => '\d+'], defaults: ['findingId' => null])]
    public function new(Request $request, ?int $findingId = null): Response
    {
        $action = new CorrectiveAction();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant instanceof Tenant) {
            $action->setTenant($tenant);
        }

        $findingLocked = false;
        if ($findingId !== null) {
            $finding = $this->entityManager->getRepository(AuditFinding::class)->find($findingId);
            if ($finding instanceof AuditFinding) {
                $action->setFinding($finding);
                $findingLocked = true;
            }
        }

        // S3 P0-30: Pre-Fill from an unwirksame Vorgänger-CAPA.
        // Tenant-Isolation: never accept a previous-CAPA from a different tenant.
        $previousCapa = null;
        $fromIneffective = $request->query->getInt('from_ineffective_capa', 0);
        if ($fromIneffective > 0) {
            $prev = $this->repository->find($fromIneffective);
            if ($prev instanceof CorrectiveAction
                && $tenant instanceof Tenant
                && $prev->getTenant()?->getId() === $tenant->getId()
                && $prev->isVerifiedIneffective()
            ) {
                $previousCapa = $prev;
                $action->setPreviousCapa($prev);
                if ($prev->getFinding() instanceof AuditFinding) {
                    $action->setFinding($prev->getFinding());
                    $findingLocked = true;
                }
                $action->setDescription(sprintf(
                    'Folge-Maßnahme zur unwirksamen CAPA #%d: %s',
                    $prev->getId() ?? 0,
                    (string) $prev->getTitle(),
                ));
                $action->setStatus(CorrectiveAction::STATUS_PLANNED); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist entity; 'planned' is the corrective_action_lifecycle initial_marking)
            }
        }

        $form = $this->createForm(CorrectiveActionType::class, $action, ['finding_locked' => $findingLocked]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($action);
            $this->entityManager->flush();

            $this->auditLogger->logCreate(
                'CorrectiveAction',
                $action->getId(),
                [
                    'title' => $action->getTitle(),
                    'status' => $action->getStatus(),
                    'previousCapaId' => $action->getPreviousCapa()?->getId(),
                ],
                'CorrectiveAction created'
            );

            $this->addFlash('success', 'corrective_action.flash.created');
            return $this->redirectToRoute('app_corrective_action_show', ['id' => $action->getId()]);
        }

        return $this->render('corrective_action/new.html.twig', [
            'form' => $form,
            'finding' => $action->getFinding(),
            'previousCapa' => $previousCapa,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(CorrectiveAction $action): Response
    {
        $this->denyIfWrongTenant($action);

        // V4 LB-4: Comment-Thread adoption — load thread for this CorrectiveAction.
        $comments = [];
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($this->commentRepository !== null && $tenant !== null && $action->getId() !== null) {
            $comments = $this->commentRepository->findThread($tenant, 'CorrectiveAction', $action->getId());
        }

        return $this->render('corrective_action/show.html.twig', [
            'action' => $action,
            'comments' => $comments,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, CorrectiveAction $action): Response
    {
        $this->denyIfWrongTenant($action);

        $form = $this->createForm(CorrectiveActionType::class, $action, ['finding_locked' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->auditLogger->logUpdate(
                'CorrectiveAction',
                $action->getId(),
                [],
                ['status' => $action->getStatus()],
                'CorrectiveAction updated'
            );

            $this->addFlash('success', 'corrective_action.flash.updated');
            return $this->redirectToRoute('app_corrective_action_show', ['id' => $action->getId()]);
        }

        return $this->render('corrective_action/edit.html.twig', [
            'action' => $action,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, CorrectiveAction $action): Response
    {
        $this->denyIfWrongTenant($action);

        if (!$this->isCsrfTokenValid('delete_ca_' . $action->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $findingId = $action->getFinding()?->getId();
        $this->auditLogger->logDelete('CorrectiveAction', $action->getId(), ['title' => $action->getTitle()], 'CorrectiveAction deleted');
        $this->entityManager->remove($action);
        $this->entityManager->flush();

        $this->addFlash('success', 'corrective_action.flash.deleted');
        return $findingId
            ? $this->redirectToRoute('app_audit_finding_show', ['id' => $findingId])
            : $this->redirectToRoute('app_corrective_action_index');
    }

    private function denyIfWrongTenant(CorrectiveAction $action): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant || $action->getTenant()?->getId() !== $tenant->getId()) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                throw $this->createAccessDeniedException('Action belongs to a different tenant.');
            }
        }
    }
}
