<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\KpiSnapshotRepository;
use App\Service\AiAgentInventoryService;
use App\Service\MrisKpiService;
use App\Service\MrisMaturityService;
use App\Service\MrisScoreService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dedizierter MRIS-Audit-Report (PDF) für Auditoren / interne Reviews.
 *
 * Quellen:
 *  - Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
 *  - Lizenz: CC BY 4.0.
 *
 * Audit-Disclaimer: Der aggregierte Mythos-Resilience-Indikator (MRI) ist eine
 * LIH-spezifische Hilfsgröße. MRIS v1.5 selbst definiert keine aggregierte
 * Bewertung. Audit-relevant sind die Einzeldimensionen.
 */
#[IsGranted('ROLE_AUDITOR')]
final class MrisAuditReportController extends AbstractController
{
    private const KPI_SPARK_IDS = [
        'mttc' => 'mris_mttc',
        'phishing_resistant_mfa_share' => 'mris_phishing_resistant_mfa_share',
        'restore_test_success_rate' => 'mris_restore_test_success_rate',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MrisScoreService $scoreService,
        private readonly MrisKpiService $kpiService,
        private readonly MrisMaturityService $maturityService,
        private readonly AiAgentInventoryService $aiAgentInventoryService,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly KpiSnapshotRepository $kpiSnapshotRepository,
        private readonly PdfExportService $pdfExportService,
    ) {
    }

    #[Route('/mris/audit-report', name: 'app_mris_audit_report', methods: ['GET'])]
    public function generate(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            $this->addFlash('warning', 'Kein Mandant zugewiesen — MRIS-Audit-Report benötigt einen Mandantenkontext.');
            return $this->redirectToRoute('app_dashboard');
        }

        $score = $this->scoreService->compute($tenant);
        $controls = $this->controlRepository->findByTenant($tenant);
        $categoryDistribution = $this->buildCategoryDistribution($controls);
        $frictionControls = $this->buildFrictionControls($controls);
        $maturityRows = $this->buildMaturityRows();
        $aiInventory = $this->aiAgentInventoryService->inventoryStats($tenant);
        $kpis = $this->kpiService->computeAll($tenant);
        $trends = $this->buildTrends($tenant, array_values(self::KPI_SPARK_IDS), 90);

        $generatedAt = new DateTimeImmutable();
        $user = $this->getUser();
        $userLabel = method_exists($user, 'getFullName') && $user->getFullName()
            ? (string) $user->getFullName()
            : (string) ($user?->getUserIdentifier() ?? 'unbekannt');

        $data = [
            'tenant' => $tenant,
            'tenant_name' => (string) ($tenant->getName() ?? 'Mandant'),
            'generated_at' => $generatedAt,
            'generated_by' => $userLabel,
            'score' => $score,
            'category_distribution' => $categoryDistribution,
            'friction_controls' => $frictionControls,
            'maturity_rows' => $maturityRows,
            'ai_inventory' => $aiInventory,
            'kpis' => $kpis,
            'trends' => $trends,
            'spark_kpi_ids' => self::KPI_SPARK_IDS,
            'source_label' => 'Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5. CC BY 4.0.',
        ];

        $filename = sprintf('MRIS_Audit_Report_%s.pdf', $generatedAt->format('Y-m-d_His'));

        $pdf = $this->pdfExportService->generatePdf(
            'mris/audit_report_pdf.html.twig',
            $data,
            ['orientation' => 'portrait', 'paper' => 'A4']
        );

        $safeFilename = preg_replace('/[^\w\s\.\-]/', '', $filename);

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $safeFilename . '"');
        $response->headers->set('Content-Length', (string) strlen($pdf));

        return $response;
    }

    /**
     * @param array<int, \App\Entity\Control> $controls
     * @return array{rows: list<array{key: string, label: string, count: int, share: float}>, total: int}
     */
    private function buildCategoryDistribution(array $controls): array
    {
        $buckets = [
            'standfest' => 0,
            'degradiert' => 0,
            'reibung' => 0,
            'nicht_betroffen' => 0,
            'unklassifiziert' => 0,
        ];
        foreach ($controls as $control) {
            $cat = $control->getMythosResilience();
            if ($cat === null || !isset($buckets[$cat])) {
                $buckets['unklassifiziert']++;
                continue;
            }
            $buckets[$cat]++;
        }
        $total = count($controls);
        $labels = [
            'standfest' => 'Standfest (S)',
            'degradiert' => 'Teilweise degradiert (T)',
            'reibung' => 'Reine Reibung (R)',
            'nicht_betroffen' => 'Nicht betroffen (N)',
            'unklassifiziert' => 'Nicht klassifiziert',
        ];
        $rows = [];
        foreach ($buckets as $key => $count) {
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key],
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100.0, 1) : 0.0,
            ];
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<int, \App\Entity\Control> $controls
     * @return list<array{control_id: string, name: string, category: string, flanking: array<int, string>}>
     */
    private function buildFrictionControls(array $controls): array
    {
        $rows = [];
        foreach ($controls as $control) {
            if ($control->getMythosResilience() !== 'reibung') {
                continue;
            }
            $rows[] = [
                'control_id' => (string) ($control->getControlId() ?? ''),
                'name' => (string) ($control->getName() ?? ''),
                'category' => (string) ($control->getCategory() ?? ''),
                'flanking' => $control->getMythosFlankingMhcs() ?? [],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['control_id'], $b['control_id']));
        return $rows;
    }

    /**
     * Liefert pro MHC-Requirement Soll/Ist/Delta. Reihenfolge nach RequirementId.
     *
     * @return list<array{mhc: string, title: string, target: ?string, current: ?string, delta: ?int, gap_status: string}>
     */
    private function buildMaturityRows(): array
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => MrisScoreService::FRAMEWORK_CODE]);
        if (!$framework instanceof ComplianceFramework) {
            return [];
        }
        $reqs = $this->requirementRepository->findBy(['framework' => $framework]);
        $rows = [];
        foreach ($reqs as $req) {
            $rows[] = [
                'mhc' => (string) ($req->getRequirementId() ?? ''),
                'title' => (string) ($req->getTitle() ?? ''),
                'target' => $req->getMaturityTarget(),
                'current' => $req->getMaturityCurrent(),
                'delta' => $this->maturityService->delta($req),
                'gap_status' => $this->maturityService->gapStatus($req),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strnatcmp($a['mhc'], $b['mhc']));
        return $rows;
    }

    /**
     * @param array<int, string> $kpiIds
     * @return array<string, array<int, float>>
     */
    private function buildTrends(Tenant $tenant, array $kpiIds, int $days): array
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
}
