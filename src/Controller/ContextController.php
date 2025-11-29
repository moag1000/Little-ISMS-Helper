<?php

namespace App\Controller;

use App\Form\ISMSContextType;
use App\Repository\AuditLogRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Service\ISMSContextService;
use App\Service\ISMSObjectiveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContextController extends AbstractController
{
    public function __construct(
        private readonly ISMSContextService $ismsContextService,
        private readonly ISMSObjectiveService $ismsObjectiveService,
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly TranslatorInterface $translator
    ) {}
    #[Route('/context/', name: 'app_context_index')]
    public function index(): Response
    {
        $context = $this->ismsContextService->getCurrentContext();
        $effectiveContext = $this->ismsContextService->getEffectiveContext($context);
        $inheritanceInfo = $this->ismsContextService->getContextInheritanceInfo($context);
        $statistics = $this->ismsObjectiveService->getStatistics();

        // Get audit log history for the EFFECTIVE context (last 10 entries) if context exists
        $auditLogs = [];
        $totalAuditLogs = 0;
        if ($effectiveContext->getId()) {
            $auditLogs = $this->auditLogRepository->findByEntity('ISMSContext', $effectiveContext->getId());
            $totalAuditLogs = count($auditLogs);
            $auditLogs = array_slice($auditLogs, 0, 10);
        }

        // Get current tenant for navigation
        $tenant = $context->getTenant();

        // Get objectives for KPI display and table
        $objectives = $this->ismsObjectiveRepository->findActive();

        return $this->render('context/index.html.twig', [
            'context' => $effectiveContext, // Show effective context
            'ownContext' => $context, // Keep reference to own context
            'completeness' => $this->ismsContextService->calculateCompleteness($effectiveContext),
            'isReviewDue' => $this->ismsContextService->isReviewDue($effectiveContext),
            'daysUntilReview' => $this->ismsContextService->getDaysUntilReview($effectiveContext),
            'statistics' => $statistics,
            'objectives' => $objectives, // Active objectives for KPI and table display
            'auditLogs' => $auditLogs,
            'totalAuditLogs' => $totalAuditLogs,
            'inheritanceInfo' => $inheritanceInfo, // NEW: Corporate inheritance info
            'canEdit' => $this->ismsContextService->canEditContext($context), // NEW: Edit permission
            'tenant' => $tenant, // NEW: Current tenant for navigation links
        ]);
    }
    #[Route('/context/edit', name: 'app_context_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request): Response
    {
        $context = $this->ismsContextService->getCurrentContext();

        // Check if context can be edited (not inherited)
        if (!$this->ismsContextService->canEditContext($context)) {
            $inheritanceInfo = $this->ismsContextService->getContextInheritanceInfo($context);
            $parentName = $inheritanceInfo['inheritedFrom'] ? $inheritanceInfo['inheritedFrom']->getName() : '';

            $this->addFlash('danger', $this->translator->trans('corporate.inheritance.cannot_edit_inherited_long', [
                '%parent%' => $parentName
            ]));

            return $this->redirectToRoute('app_context_index');
        }

        $form = $this->createForm(ISMSContextType::class, $context);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $validationErrors = $this->ismsContextService->validateContext($context);

            foreach ($validationErrors as $validationError) {
                $this->addFlash('warning', $validationError);
            }

            $this->ismsContextService->saveContext($context);

            $this->addFlash('success', $this->translator->trans('context.success.updated'));
            return $this->redirectToRoute('app_context_index');
        }

        // Get current tenant for navigation
        $tenant = $context->getTenant();

        return $this->render('context/edit.html.twig', [
            'context' => $context,
            'form' => $form,
            'tenant' => $tenant, // NEW: Current tenant for navigation links
        ]);
    }
}
