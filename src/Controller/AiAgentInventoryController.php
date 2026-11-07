<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Service\AiAgentInventoryService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Util\ListPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AI-Agent-Inventar (Asset-Subtyp 'ai_agent').
 *
 * Erfüllt EU AI Act Art. 6/9-16, ISO 42001 Annex A, MRIS MHC-13 (Peddi 2026,
 * CC BY 4.0) sowie ISO 27001 A.5.16/A.8.27 — eine Datenbasis, vier Frameworks.
 */
#[IsGranted('ROLE_USER')]
final class AiAgentInventoryController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly AiAgentInventoryService $inventoryService,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/ai-agents', name: 'app_ai_agents_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('ai_governance')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('asset.ai_agent.flash.no_tenant', [], 'asset'));
            return $this->redirectToRoute('app_dashboard');
        }

        $agents = $this->inventoryService->findAllForTenant($tenant);
        $stats = $this->inventoryService->inventoryStats($tenant);
        $highRiskIncomplete = $this->inventoryService->findHighRiskWithIncompleteDocumentation($tenant);

        $page = ListPage::fromRequest(
            $agents,
            $request,
            static fn($a): array => [$a->getName(), $a->getAiAgentProvider(), $a->getAiAgentModelVersion()],
        );

        // Compliance-Vollständigkeit pro Agent der aktuellen Seite
        $completeness = [];
        foreach ($page as $agent) {
            $completeness[$agent->getId()] = $this->inventoryService->complianceCompleteness($agent);
        }

        return $this->render('ai_agent/index.html.twig', [
            'page' => $page,
            'stats' => $stats,
            'completeness' => $completeness,
            'high_risk_incomplete' => $highRiskIncomplete,
        ]);
    }
}
