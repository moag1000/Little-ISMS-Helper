<?php

namespace App\Controller;

use App\Entity\ISMSContext;
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

#[Route('/context')]
class ContextController extends AbstractController
{
    public function __construct(
        private ISMSContextService $contextService,
        private ISMSObjectiveService $objectiveService,
        private ISMSObjectiveRepository $objectiveRepository,
        private AuditLogRepository $auditLogRepository,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_context_index')]
    public function index(): Response
    {
        $context = $this->contextService->getCurrentContext();
        $effectiveContext = $this->contextService->getEffectiveContext($context);
        $inheritanceInfo = $this->contextService->getContextInheritanceInfo($context);
        $statistics = $this->objectiveService->getStatistics();

        // Get audit log history for the EFFECTIVE context (last 10 entries) if context exists
        $auditLogs = [];
        $totalAuditLogs = 0;
        if ($effectiveContext && $effectiveContext->getId()) {
            $auditLogs = $this->auditLogRepository->findByEntity('ISMSContext', $effectiveContext->getId());
            $totalAuditLogs = count($auditLogs);
            $auditLogs = array_slice($auditLogs, 0, 10);
        }

        // Get current tenant for navigation
        $tenant = $context->getTenant();

        // Get objectives for KPI display and table
        $objectives = $this->objectiveRepository->findActive();

        return $this->render('context/index.html.twig', [
            'context' => $effectiveContext, // Show effective context
            'ownContext' => $context, // Keep reference to own context
            'completeness' => $this->contextService->calculateCompleteness($effectiveContext),
            'isReviewDue' => $this->contextService->isReviewDue($effectiveContext),
            'daysUntilReview' => $this->contextService->getDaysUntilReview($effectiveContext),
            'statistics' => $statistics,
            'objectives' => $objectives, // Active objectives for KPI and table display
            'auditLogs' => $auditLogs,
            'totalAuditLogs' => $totalAuditLogs,
            'inheritanceInfo' => $inheritanceInfo, // NEW: Corporate inheritance info
            'canEdit' => $this->contextService->canEditContext($context), // NEW: Edit permission
            'tenant' => $tenant, // NEW: Current tenant for navigation links
        ]);
    }

    #[Route('/edit', name: 'app_context_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request): Response
    {
        $context = $this->contextService->getCurrentContext();

        // Check if context can be edited (not inherited)
        if (!$this->contextService->canEditContext($context)) {
            $inheritanceInfo = $this->contextService->getContextInheritanceInfo($context);
            $parentName = $inheritanceInfo['inheritedFrom'] ? $inheritanceInfo['inheritedFrom']->getName() : '';

            $this->addFlash('danger', $this->translator->trans('corporate.inheritance.cannot_edit_inherited_long', [
                '%parent%' => $parentName
            ]));

            return $this->redirectToRoute('app_context_index');
        }

        $form = $this->createForm(ISMSContextType::class, $context);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $validationErrors = $this->contextService->validateContext($context);

            if (!empty($validationErrors)) {
                foreach ($validationErrors as $error) {
                    $this->addFlash('warning', $error);
                }
            }

            $this->contextService->saveContext($context);

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
