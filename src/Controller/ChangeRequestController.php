<?php

namespace App\Controller;

use DateTimeImmutable;
use App\Entity\ChangeRequest;
use App\Form\ChangeRequestType;
use App\Repository\ChangeRequestRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/change-request/', name: 'app_change_request_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $changeRequests = $this->changeRequestRepository->findAll();
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
    #[Route('/change-request/new', name: 'app_change_request_new')]
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

            $this->addFlash('success', $this->translator->trans('change_request.success.created'));
            return $this->redirectToRoute('app_change_request_show', ['id' => $changeRequest->getId()]);
        }

        return $this->render('change_request/new.html.twig', [
            'change_request' => $changeRequest,
            'form' => $form,
        ]);
    }
    #[Route('/change-request/{id}', name: 'app_change_request_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(ChangeRequest $changeRequest): Response
    {
        return $this->render('change_request/show.html.twig', [
            'change_request' => $changeRequest,
        ]);
    }
    #[Route('/change-request/{id}/edit', name: 'app_change_request_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, ChangeRequest $changeRequest): Response
    {
        $form = $this->createForm(ChangeRequestType::class, $changeRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $changeRequest->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('change_request.success.updated'));
            return $this->redirectToRoute('app_change_request_show', ['id' => $changeRequest->getId()]);
        }

        return $this->render('change_request/edit.html.twig', [
            'change_request' => $changeRequest,
            'form' => $form,
        ]);
    }
    #[Route('/change-request/{id}/delete', name: 'app_change_request_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ChangeRequest $changeRequest): Response
    {
        if ($this->isCsrfTokenValid('delete'.$changeRequest->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($changeRequest);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('change_request.success.deleted'));
        }

        return $this->redirectToRoute('app_change_request_index');
    }
}
