<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use App\Entity\ChangeRequest;
use App\Form\ChangeRequestType;
use App\Repository\ChangeRequestRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly ChangeRequestRepository $changeRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
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

            $this->addFlash('success', $this->translator->trans('change_request.success.created')); // @todo H-06 flash-domain
            return $this->redirectToRoute('app_change_request_show', ['id' => $changeRequest->getId()]);
        }

        return $this->render('change_request/new.html.twig', [
            'change_request' => $changeRequest,
            'form' => $form,
        ]);
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

            $this->addFlash('success', $this->translator->trans('change_request.success.updated')); // @todo H-06 flash-domain
            return $this->redirectToRoute('app_change_request_show', ['id' => $changeRequest->getId()]);
        }

        return $this->render('change_request/edit.html.twig', [
            'change_request' => $changeRequest,
            'form' => $form,
        ]);
    }
    #[Route('/change-request/{id}/delete', name: 'app_change_request_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ChangeRequest $changeRequest): Response
    {
        if ($this->isCsrfTokenValid('delete'.$changeRequest->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($changeRequest);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('change_request.success.deleted')); // @todo H-06 flash-domain
        }

        return $this->redirectToRoute('app_change_request_index');
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
}
