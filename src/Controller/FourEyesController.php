<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Form\FourEyesApprovalRequestType;
use App\Service\FourEyesApprovalService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/four-eyes', name: 'app_four_eyes_')]
#[IsGranted('ROLE_MANAGER')]
final class FourEyesController extends AbstractController
{
    public function __construct(
        private readonly FourEyesApprovalService $service,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(FourEyesApprovalRequest $request, Request $httpRequest): Response
    {
        if (!$this->isCsrfTokenValid('four_eyes_approve_' . $request->getId(), $httpRequest->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->assertSameTenant($request);
        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->service->approve($request, $user);
            $this->addFlash('success', 'four_eyes.flash.approved');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('app_dashboard'));
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(FourEyesApprovalRequest $request, Request $httpRequest): Response
    {
        if (!$this->isCsrfTokenValid('four_eyes_reject_' . $request->getId(), $httpRequest->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->assertSameTenant($request);
        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->service->reject($request, $user, (string) $httpRequest->request->get('reason', ''));
            $this->addFlash('success', 'four_eyes.flash.rejected');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('app_dashboard'));
    }

    /**
     * Edit the approver Tri-State fields of a pending approval request.
     * Only ADMIN users may reassign approvers; the request must still be pending.
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(FourEyesApprovalRequest $approvalRequest, Request $httpRequest): Response
    {
        $this->assertSameTenant($approvalRequest);

        $form = $this->createForm(FourEyesApprovalRequestType::class, $approvalRequest);
        $form->handleRequest($httpRequest);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'four_eyes.flash.approver_updated');

            return $this->redirectToRoute('app_four_eyes_inbox');
        }

        return $this->render('four_eyes/edit.html.twig', [
            'approvalRequest' => $approvalRequest,
            'form' => $form,
        ]);
    }

    private function assertSameTenant(FourEyesApprovalRequest $request): void
    {
        $current = $this->tenantContext->getCurrentTenant();
        if ($current === null || $request->getTenant()?->getId() !== $current->getId()) {
            throw $this->createAccessDeniedException('Tenant mismatch.');
        }
    }
}
