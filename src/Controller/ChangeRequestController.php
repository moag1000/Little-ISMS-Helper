<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use App\Controller\Trait\BulkActionTrait;
use App\Entity\ChangeRequest;
use App\Form\ChangeRequestType;
use App\Repository\ChangeRequestRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChangeRequestController extends AbstractController
{
    use BulkActionTrait;

    public function __construct(
        private readonly ChangeRequestRepository $changeRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}
    #[Route('/change-request', name: 'app_change_request_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $changeRequests = $tenant ? $this->changeRequestRepository->findBy(['tenant' => $tenant]) : [];
        $statistics = $this->changeRequestRepository->getStatistics();
        $pendingApproval = $this->changeRequestRepository->findPendingApproval();
        $overdue = $this->changeRequestRepository->findOverdue();

        return $this->render('change_request/index.html.twig', [
            'change_requests' => $changeRequests,
            'statistics' => $statistics,
            'pending_approval' => $pendingApproval,
            'overdue' => $overdue,
        ]);
    }
    #[Route('/change-request/new', name: 'app_change_request_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $changeRequest = new ChangeRequest();
        $changeRequest->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(ChangeRequestType::class, $changeRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($changeRequest);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('change_request.success.created', [], 'messages'));
            return $this->redirectToRoute('app_change_request_show', ['id' => $changeRequest->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('change_request/new.html.twig', [
            'change_request' => $changeRequest,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/change-request/{id}', name: 'app_change_request_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(ChangeRequest $changeRequest): Response
    {
        return $this->render('change_request/show.html.twig', [
            'change_request' => $changeRequest,
        ]);
    }
    #[Route('/change-request/{id}/edit', name: 'app_change_request_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, ChangeRequest $changeRequest): Response
    {
        $form = $this->createForm(ChangeRequestType::class, $changeRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $changeRequest->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('change_request.success.updated', [], 'messages'));
            return $this->redirectToRoute('app_change_request_show', ['id' => $changeRequest->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('change_request/edit.html.twig', [
            'change_request' => $changeRequest,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/change-request/{id}/delete', name: 'app_change_request_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ChangeRequest $changeRequest): Response
    {
        if ($this->isCsrfTokenValid('delete'.$changeRequest->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($changeRequest);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('change_request.success.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_change_request_index');
    }

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * ChangeRequests have no blocking FK relations — returns empty dependencies.
     */
    #[Route('/change-request/bulk-delete-check', name: 'app_change_request_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = (array) ($data['ids'] ?? []);
        return new JsonResponse(['dependencies' => [], 'checked_count' => count($ids)]);
    }

    #[Route('/change-request/bulk-delete', name: 'app_change_request_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $changeRequest = $this->changeRequestRepository->find($id);
                if (!$changeRequest) {
                    $errors[] = "ChangeRequest ID $id not found";
                    continue;
                }
                if ($tenant && $changeRequest->getTenant() !== $tenant) {
                    $errors[] = "ChangeRequest ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($changeRequest);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting ChangeRequest ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted change requests deleted successfully",
        ]);
    }

    /**
     * Bulk CSV export of selected change requests.
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/change-request/bulk-export', name: 'app_change_request_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request): StreamedResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        $changeRequests = [];
        foreach ($ids as $rawId) {
            $cr = $this->changeRequestRepository->find((int) $rawId);
            if ($cr === null) {
                continue;
            }
            if ($tenant !== null && $cr->getTenant() !== $tenant) {
                continue;
            }
            $changeRequests[] = $cr;
        }

        if ($changeRequests === []) {
            return $this->json(['error' => 'No exportable change requests'], 404);
        }

        $headers = ['ID', 'Title', 'Status', 'Priority', 'Requested By', 'Description'];

        return $this->streamCsvExport(
            $changeRequests,
            $headers,
            static function (ChangeRequest $cr): array {
                return [
                    (string) $cr->getId(),
                    (string) $cr->getTitle(),
                    (string) $cr->getStatus(),
                    (string) $cr->getPriority(),
                    (string) $cr->getRequestedBy(),
                    (string) $cr->getDescription(),
                ];
            },
            'change-requests-export',
            'ChangeRequest',
            $this->auditLogger,
        );
    }
}
