<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\KpiSnapshotRepository;
use App\Service\MrisKpiService;
use App\Service\MrisScoreService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
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
        private readonly MrisScoreService $scoreService,
        private readonly KpiSnapshotRepository $kpiSnapshotRepository,
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

        // Featureflag: standardmäßig aktiv. Mandanten-Admins können MRIS-KPIs
        // deaktivieren wenn nicht relevant (z. B. nicht-DORA, nicht-NIS2).
        $settings = $tenant->getSettings() ?? [];
        $enabled = $settings['mris']['kpis_enabled'] ?? true;
        if ($enabled === false) {
            $this->addFlash('info', 'MRIS-KPIs sind für diesen Mandanten deaktiviert. Aktivierung über Mandanten-Einstellungen.');
            return $this->redirectToRoute('app_dashboard');
        }

        $kpis = $this->kpiService->computeAll($tenant);
        $manualKpis = array_values(array_filter($kpis, static fn(array $k): bool => $k['computable'] === false));

        // Aggregierter Mythos-Resilience-Indikator (LIH-spezifische Hilfsgroesse).
        $score = $this->scoreService->compute($tenant);

        // Trend-Daten der 3 auto-KPIs (letzte 90 Tage aus KpiSnapshot).
        $trends = $this->buildTrends($tenant, ['mris_mttc', 'mris_phishing_resistant_mfa_share', 'mris_restore_test_success_rate'], 90);

        return $this->render('mris/kpis.html.twig', [
            'kpis' => $kpis,
            'manual_kpis' => $manualKpis,
            'tenant' => $tenant,
            'score' => $score,
            'trends' => $trends,
        ]);
    }

    /**
     * Liefert pro KPI-ID die Werteliste der letzten N Tage aus KpiSnapshot.
     *
     * @param array<int, string> $kpiIds
     * @return array<string, array<int, float>>
     */
    private function buildTrends(\App\Entity\Tenant $tenant, array $kpiIds, int $days): array
    {
        $snapshots = $this->kpiSnapshotRepository->findRecentByTenant($tenant, $days);
        $trends = array_fill_keys($kpiIds, []);
        foreach ($snapshots as $snap) {
            $data = $snap->getKpiData() ?? [];
            foreach ($kpiIds as $id) {
                if (isset($data[$id]) && is_numeric($data[$id])) {
                    $trends[$id][] = (float) $data[$id];
                }
            }
        }
        return $trends;
    }

    /**
     * Speichert manuelle KPI-Werte. ROLE_MANAGER analog zu MHC-Maturity-Save.
     */
    #[Route('/mris/kpis/manual', name: 'app_mris_kpis_manual_save', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('mris_manual_kpis', tokenKey: '_token')]
    public function saveManual(Request $request): Response
    {
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
