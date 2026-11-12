<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\SafeRedirectTarget;
use Symfony\Contracts\Translation\TranslatorInterface;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Form\FourEyesApprovalRequestType;
use App\Lifecycle\FourEyes\LifecycleCountersign;
use App\Service\FourEyesApprovalService;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/four-eyes', name: 'app_four_eyes_')]
final class FourEyesController extends AbstractController
{
    public function __construct(
        private readonly FourEyesApprovalService $service,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LifecycleCountersign $lifecycleCountersign,
        private readonly \App\Service\AuditLogger $auditLogger,
    ) {
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'request')]
    public function approve(FourEyesApprovalRequest $request, Request $httpRequest): Response
    {
        if (!$this->isCsrfTokenValid('four_eyes_approve_' . $request->getId(), $httpRequest->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->assertSameTenant($request);
        /** @var User $user */
        $user = $this->getUser();

        try {
            // S27: a lifecycle counter-sign request applies the transition with this
            // person — logged in here — as the second person.
            if ($request->getActionType() === FourEyesApprovalRequest::ACTION_LIFECYCLE_TRANSITION) {
                $this->lifecycleCountersign->confirm($request, $user);
            } else {
                $this->service->approve($request, $user);
            }
            $this->addFlash('success', $this->translator->trans('four_eyes.flash.approved', [], 'four_eyes'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToInbox($httpRequest);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'request')]
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
            $this->addFlash('success', $this->translator->trans('four_eyes.flash.rejected', [], 'four_eyes'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToInbox($httpRequest);
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

        if (!$approvalRequest->isPending()) {
            // S34 (R3a): a decided request keeps the people it was decided by.
            $this->addFlash('danger', $this->translator->trans('four_eyes.flash.not_pending', [], 'four_eyes'));

            return $this->redirectToRoute('app_four_eyes_inbox');
        }

        $before = $approvalRequest->getRequestedApprover()?->getId();
        $form = $this->createForm(FourEyesApprovalRequestType::class, $approvalRequest);
        $form->handleRequest($httpRequest);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->auditLogger->logCustom(
                'four_eyes.approver_reassigned',
                'FourEyesApprovalRequest',
                $approvalRequest->getId(),
                ['approver_id' => $before],
                ['approver_id' => $approvalRequest->getRequestedApprover()?->getId()],
                'Designated approver of a pending four-eyes request changed',
            );

            $this->addFlash('success', $this->translator->trans('four_eyes.flash.approver_updated', [], 'four_eyes'));

            return $this->redirectToRoute('app_four_eyes_inbox');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('four_eyes/edit.html.twig', [
            'approvalRequest' => $approvalRequest,
            'form' => $form,
        ], new Response(status: $status));
    }

    private function assertSameTenant(FourEyesApprovalRequest $request): void
    {
        $current = $this->tenantContext->getCurrentTenant();
        if ($current === null || $request->getTenant()?->getId() !== $current->getId()) {
            throw $this->createAccessDeniedException('Tenant mismatch.');
        }
    }

    /** Back to the page the decision was made on (My Day or the four-eyes inbox). */
    private function backToInbox(Request $httpRequest): Response
    {
        $referer = (string) $httpRequest->headers->get('referer', '');
        if ($referer !== '' && SafeRedirectTarget::isSafe($referer, $httpRequest)) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_four_eyes_inbox');
    }
}
