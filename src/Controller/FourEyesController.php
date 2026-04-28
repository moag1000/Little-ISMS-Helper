<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Service\FourEyesApprovalService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/four-eyes', name: 'app_four_eyes_')]
#[IsGranted('ROLE_MANAGER')]
final class FourEyesController extends AbstractController
{
    public function __construct(
        private readonly FourEyesApprovalService $service,
        private readonly TenantContext $tenantContext,
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

    private function assertSameTenant(FourEyesApprovalRequest $request): void
    {
        $current = $this->tenantContext->getCurrentTenant();
        if ($current === null || $request->getTenant()?->getId() !== $current->getId()) {
            throw $this->createAccessDeniedException('Tenant mismatch.');
        }
    }
}
