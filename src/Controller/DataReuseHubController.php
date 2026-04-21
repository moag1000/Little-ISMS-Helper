<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DataReuseHubService;
use App\Service\InheritanceMetricsService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Data-Reuse-Hub (Sprint 4 / R1).
 *
 * Erster First-Class-Einstiegspunkt für Data-Reuse. Beantwortet
 * kontextuell die drei CM-Kernfragen:
 *   1. *"Wie viel FTE-Zeit spart mir die Mapping-Vererbung?"*
 *      → zentrale KPI-Kachel aus `InheritanceMetricsService`.
 *   2. *"Welche Dokumente/Lieferanten ziehen die größte Last?"*
 *      → Top-10-Listen pro Entity-Typ, sortiert nach
 *        referenzierenden Anforderungen + Framework-Breite.
 *   3. *"Wie verteilt sich die Abdeckung?"*
 *      → Stats-Bar mit „N von M wiederverwendet" pro Typ.
 *
 * Die UI ist bewusst linear (Stats → Dokumente → Lieferanten) statt
 * Tab-basiert — beide Listen passen auf einen Screen und der Junior
 * sieht das ganze Bild ohne Click-Through.
 */
#[IsGranted('ROLE_MANAGER')]
class DataReuseHubController extends AbstractController
{
    public function __construct(
        private readonly DataReuseHubService $hubService,
        private readonly InheritanceMetricsService $inheritanceMetricsService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/reuse', name: 'app_data_reuse_hub', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        $fteSaved = $tenant !== null
            ? $this->inheritanceMetricsService->fteSavedForTenant($tenant)
            : 0.0;

        $stats = $this->hubService->portfolioStats($tenant);
        $documents = $this->hubService->topDocumentsByReuse($tenant);
        $suppliers = $this->hubService->topSuppliersByReuse($tenant);

        return $this->render('data_reuse_hub/index.html.twig', [
            'tenant' => $tenant,
            'fte_saved' => $fteSaved,
            'stats' => $stats,
            'top_documents' => $documents,
            'top_suppliers' => $suppliers,
        ]);
    }

    /**
     * Sprint 6 / R6 — Per-Entity-Reuse-Heatmap.
     *
     * Gibt jedem wiederverwendeten Dokument und Lieferanten eine farbige
     * Kachel, intensiv nach Anzahl referenzierender Requirements. Dient
     * dem Management-Review zur Frage *„welche Artefakte tragen das
     * ISMS?"* und zeigt Monokultur-Risiken (wenn ein Dokument 80 %
     * der Last trägt).
     */
    #[Route('/reuse/heatmap', name: 'app_data_reuse_heatmap', methods: ['GET'])]
    public function heatmap(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        $documents = $this->hubService->topDocumentsByReuse($tenant, 200);
        $suppliers = $this->hubService->topSuppliersByReuse($tenant, 200);

        $docMax = 0;
        foreach ($documents as $d) {
            $docMax = max($docMax, $d['requirement_count']);
        }
        $supMax = 0;
        foreach ($suppliers as $s) {
            $supMax = max($supMax, $s['requirement_count']);
        }

        return $this->render('data_reuse_hub/heatmap.html.twig', [
            'documents' => $documents,
            'suppliers' => $suppliers,
            'doc_max' => $docMax,
            'sup_max' => $supMax,
        ]);
    }
}
