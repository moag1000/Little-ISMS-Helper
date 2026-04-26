<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MrisKpiService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mythos-relevante KPIs gem. MRIS v1.5 Kap. 10.6.
 *
 * Quelle: Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
 * Lizenz: CC BY 4.0.
 */
#[IsGranted('ROLE_USER')]
final class MrisKpiController extends AbstractController
{
    public function __construct(
        private readonly MrisKpiService $kpiService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/mris/kpis', name: 'app_mris_kpis', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'Kein Mandant zugewiesen — MRIS-KPIs benötigen einen Mandantenkontext.');
            return $this->redirectToRoute('app_dashboard');
        }

        $kpis = $this->kpiService->computeAll($tenant);

        return $this->render('mris/kpis.html.twig', [
            'kpis' => $kpis,
            'tenant' => $tenant,
        ]);
    }
}
