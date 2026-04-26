<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AiAgentInventoryService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AI-Agent-Inventar (Asset-Subtyp 'ai_agent').
 *
 * Erfüllt EU AI Act Art. 6/9-16, ISO 42001 Annex A, MRIS MHC-13 (Peddi 2026,
 * CC BY 4.0) sowie ISO 27001 A.5.16/A.8.27 — eine Datenbasis, vier Frameworks.
 */
#[IsGranted('ROLE_USER')]
final class AiAgentInventoryController extends AbstractController
{
    public function __construct(
        private readonly AiAgentInventoryService $inventoryService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/ai-agents', name: 'app_ai_agents_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'Kein Mandant zugewiesen — AI-Agent-Inventar benötigt einen Mandantenkontext.');
            return $this->redirectToRoute('app_dashboard');
        }

        $agents = $this->inventoryService->findAllForTenant($tenant);
        $stats = $this->inventoryService->inventoryStats($tenant);
        $highRiskIncomplete = $this->inventoryService->findHighRiskWithIncompleteDocumentation($tenant);

        // Compliance-Vollständigkeit pro Agent für die Tabelle
        $completeness = [];
        foreach ($agents as $agent) {
            $completeness[$agent->getId()] = $this->inventoryService->complianceCompleteness($agent);
        }

        return $this->render('ai_agent/index.html.twig', [
            'agents' => $agents,
            'stats' => $stats,
            'completeness' => $completeness,
            'high_risk_incomplete' => $highRiskIncomplete,
        ]);
    }
}
