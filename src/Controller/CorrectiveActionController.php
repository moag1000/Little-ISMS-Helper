<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\InPageFormTrait;
use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Enum\CorrectiveActionStatus;
use App\Form\CorrectiveActionType;
use App\Repository\AuditFindingRepository;
use App\Repository\CommentRepository;
use App\Repository\CorrectiveActionRepository;
use App\Service\CorrectiveActionService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * H-01: CRUD for Corrective Actions (ISO 27001 Clause 10.1).
 */
#[IsGranted('ROLE_USER')]
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/corrective-action', name: 'app_corrective_action_')]
class CorrectiveActionController extends AbstractController
{
    use InPageFormTrait;

    public function __construct(
        private readonly CorrectiveActionRepository $repository,
        private readonly CorrectiveActionService $correctiveActionService,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly AuditFindingRepository $auditFindingRepository,
        private readonly ?CommentRepository $commentRepository = null,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Junior-ISB-Audit-2026-05-22 M-07 Phase-1 — sourceType filter (ADR 2026-05-23).
        $validSourceTypes = [
            CorrectiveAction::SOURCE_TYPE_AUDIT_FINDING,
            CorrectiveAction::SOURCE_TYPE_INCIDENT,
            CorrectiveAction::SOURCE_TYPE_CHANGE_REQUEST,
            CorrectiveAction::SOURCE_TYPE_MANUAL,
        ];
        $sourceTypeFilter = (string) $request->query->get('source_type', '');
        if ($sourceTypeFilter !== '' && !in_array($sourceTypeFilter, $validSourceTypes, true)) {
            $sourceTypeFilter = '';
        }

        $criteria = $tenant instanceof Tenant ? ['tenant' => $tenant] : [];
        $allActions = $this->repository->findBy($criteria, ['createdAt' => 'DESC']);

        // Count per source_type for chip labels (uses unfiltered set so chip counts stay stable).
        $countsBySourceType = [
            CorrectiveAction::SOURCE_TYPE_AUDIT_FINDING => 0,
            CorrectiveAction::SOURCE_TYPE_INCIDENT => 0,
            CorrectiveAction::SOURCE_TYPE_CHANGE_REQUEST => 0,
            CorrectiveAction::SOURCE_TYPE_MANUAL => 0,
        ];
        foreach ($allActions as $ca) {
            $st = $ca->getSourceType();
            $countsBySourceType[$st] = ($countsBySourceType[$st] ?? 0) + 1;
        }

        $actions = $sourceTypeFilter === ''
            ? $allActions
            : array_values(array_filter(
                $allActions,
                static fn (CorrectiveAction $ca): bool => $ca->getSourceType() === $sourceTypeFilter
            ));

        $overdue = $tenant instanceof Tenant ? $this->repository->findOverdue($tenant) : [];

        return $this->render('corrective_action/index.html.twig', [
            'actions' => $actions,
            'overdue_count' => count($overdue),
            'source_type_filter' => $sourceTypeFilter,
            'counts_by_source_type' => $countsBySourceType,
            'total_count' => count($allActions),
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
            $finding = $this->auditFindingRepository->find($findingId);
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
                $action->setStatus(CorrectiveActionStatus::Planned); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist entity; 'planned' is the corrective_action_lifecycle initial_marking)
            }
        }

        $form = $this->createForm(CorrectiveActionType::class, $action, ['finding_locked' => $findingLocked]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->correctiveActionService->create($action);

            $this->addFlash('success', 'corrective_action.flash.created');

            if ($this->isTurboFrameRequest($request)) {
                return $this->correctiveActionStreamSave($action, isNew: true);
            }
            return $this->redirectToRoute('app_corrective_action_show', ['id' => $action->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('corrective_action/_form_modal.html.twig', [
                'action' => $action,
                'form' => $form,
                'findingId' => $findingId,
            ], new Response(status: $status));
        }

        return $this->render('corrective_action/new.html.twig', [
            'form' => $form,
            'finding' => $action->getFinding(),
            'previousCapa' => $previousCapa,
        ], new Response(status: $status));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, CorrectiveAction $action): Response
    {
        $this->denyIfWrongTenant($action);

        // In-modal → condensed read-only detail; direct URL → full page (fallback).
        if ($this->isTurboFrameRequest($request)) {
            return $this->render('corrective_action/_detail_modal.html.twig', [
                'action' => $action,
            ]);
        }

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
            $this->correctiveActionService->update($action);

            $this->addFlash('success', 'corrective_action.flash.updated');

            if ($this->isTurboFrameRequest($request)) {
                return $this->correctiveActionStreamSave($action, isNew: false);
            }
            return $this->redirectToRoute('app_corrective_action_show', ['id' => $action->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('corrective_action/_form_modal.html.twig', [
                'action' => $action,
                'form' => $form,
                'findingId' => null,
            ], new Response(status: $status));
        }

        return $this->render('corrective_action/edit.html.twig', [
            'action' => $action,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, CorrectiveAction $action): Response
    {
        $this->denyIfWrongTenant($action);

        if (!$this->isCsrfTokenValid('delete_ca_' . $action->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $findingId = $action->getFinding()?->getId();
        $this->correctiveActionService->delete($action);

        $this->addFlash('success', 'corrective_action.flash.deleted');
        return $findingId
            ? $this->redirectToRoute('app_audit_finding_show', ['id' => $findingId])
            : $this->redirectToRoute('app_corrective_action_index');
    }

    /** Turbo Stream after a successful in-modal CorrectiveAction save (row replace/append). */
    private function correctiveActionStreamSave(CorrectiveAction $action, bool $isNew): Response
    {
        return $this->render('corrective_action/_stream_save.html.twig', [
            'action' => $action,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
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

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * CorrectiveActions are terminal — returns empty dependencies.
     */
    #[Route('/bulk-delete-check', name: 'bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = (array) ($data['ids'] ?? []);
        return new JsonResponse(['dependencies' => [], 'checked_count' => count($ids)]);
    }

    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $result = $this->correctiveActionService->bulkDelete($ids, $tenant?->getId());
        $deleted = $result['deleted'];
        $errors  = $result['errors'];

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted corrective actions deleted successfully",
        ]);
    }
}
