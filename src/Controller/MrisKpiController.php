<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MrisKpiService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        $manualKpis = array_values(array_filter($kpis, static fn(array $k): bool => $k['computable'] === false));

        return $this->render('mris/kpis.html.twig', [
            'kpis' => $kpis,
            'manual_kpis' => $manualKpis,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Speichert manuelle KPI-Werte. ROLE_MANAGER analog zu MHC-Maturity-Save.
     */
    #[Route('/mris/kpis/manual', name: 'app_mris_kpis_manual_save', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function saveManual(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mris_manual_kpis', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_mris_kpis');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'Kein Mandant zugewiesen.');
            return $this->redirectToRoute('app_dashboard');
        }

        $values = $request->request->all('manual');
        if (!is_array($values)) {
            $values = [];
        }

        $this->kpiService->setManualKpis($tenant, $values);
        $this->addFlash('success', 'Manuelle MRIS-KPI-Werte gespeichert.');
        return $this->redirectToRoute('app_mris_kpis');
    }
}
