<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\IncidentSeverity;
use DateTime;
use App\Entity\Risk;
use App\Entity\Asset;
use App\Entity\Incident;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Risk\RiskMatrixThresholds;
use App\Service\AssetCriticalityService;
use App\Service\ComplianceAnalyticsService;
use App\Service\ControlEffectivenessService;
use App\Service\RiskForecastService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Analytics Controller
 *
 * Phase 7B: Advanced Analytics Dashboards with multi-framework compliance,
 * control effectiveness, and predictive risk analytics.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/analytics')]
class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceAnalyticsService $complianceAnalyticsService,
        private readonly ControlEffectivenessService $controlEffectivenessService,
        private readonly RiskForecastService $riskForecastService,
        private readonly AssetCriticalityService $assetCriticalityService,
    ) {}
    #[Route('', name: 'app_analytics_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('analytics/dashboard.html.twig');
    }

    /**
     * Phase 7B: Advanced Analytics Hub with tabbed navigation
     */
    #[Route('/advanced', name: 'app_analytics_advanced', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function advancedDashboard(): Response
    {
        return $this->render('analytics/advanced.html.twig', [
            'compliance_summary' => $this->complianceAnalyticsService->getExecutiveSummary(),
            'control_metrics' => $this->controlEffectivenessService->getEffectivenessDashboard()['metrics'],
            'risk_velocity' => $this->riskForecastService->getRiskVelocity(),
        ]);
    }

    /**
     * Phase 7B: Multi-Framework Compliance Dashboard
     */
    #[Route('/compliance/frameworks', name: 'app_analytics_compliance_frameworks', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function complianceFrameworks(): Response
    {
        return $this->render('analytics/compliance_frameworks.html.twig', [
            'comparison' => $this->complianceAnalyticsService->getFrameworkComparison(),
            'overlap' => $this->complianceAnalyticsService->getFrameworkOverlap(),
            'roadmap' => $this->complianceAnalyticsService->getComplianceRoadmap(),
        ]);
    }

    /**
     * Phase 7B: Control Effectiveness Dashboard
     */
    #[Route('/controls/effectiveness', name: 'app_analytics_control_effectiveness', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function controlEffectiveness(): Response
    {
        return $this->render('analytics/control_effectiveness.html.twig', [
            'dashboard' => $this->controlEffectivenessService->getEffectivenessDashboard(),
            'category_performance' => $this->controlEffectivenessService->getCategoryPerformance(),
            'risk_matrix' => $this->controlEffectivenessService->getControlRiskMatrix(),
        ]);
    }

    /**
     * Phase 7B: Risk Forecast Dashboard
     */
    #[Route('/risk/forecast', name: 'app_analytics_risk_forecast', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function riskForecast(): Response
    {
        return $this->render('analytics/risk_forecast.html.twig', [
            'forecast' => $this->riskForecastService->getRiskForecast(6),
            'velocity' => $this->riskForecastService->getRiskVelocity(),
            'appetite' => $this->riskForecastService->getRiskAppetiteCompliance(),
            'anomalies' => $this->riskForecastService->getAnomalyDetection(),
        ]);
    }

    /**
     * Phase 7B: Asset Criticality Dashboard
     */
    #[Route('/assets/criticality', name: 'app_analytics_asset_criticality', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function assetCriticality(): Response
    {
        return $this->render('analytics/asset_criticality.html.twig', [
            'incident_probability' => $this->assetCriticalityService->getAssetIncidentProbability(),
            'dashboard' => $this->assetCriticalityService->getCriticalityDashboard(),
            'vulnerability_matrix' => $this->assetCriticalityService->getVulnerabilityMatrix(),
            'type_analysis' => $this->assetCriticalityService->getTypeAnalysis(),
        ]);
    }

    // ========== API Endpoints for Charts ==========

    #[Route('/api/heat-map', name: 'app_analytics_heat_map_data', methods: ['GET'])]
    public function getHeatMapData(): JsonResponse
    {
        $risks = $this->riskRepository->findAll();

        // Create 5x5 matrix (Probability x Impact)
        $matrix = array_fill(1, 5, array_fill(1, 5, []));

        foreach ($risks as $risk) {
            $probability = $risk->getProbability();
            $impact = $risk->getImpact();

            if ($probability >= 1 && $probability <= 5 && $impact >= 1 && $impact <= 5) {
                $matrix[$impact][$probability][] = [
                    'id' => $risk->getId(),
                    'title' => $risk->getTitle(),
                    'level' => $risk->getInherentRiskLevel()
                ];
            }
        }

        // Calculate counts for each cell
        $heatMapData = [];
        for ($impact = 5; $impact >= 1; $impact--) {
            for ($probability = 1; $probability <= 5; $probability++) {
                $risks = $matrix[$impact][$probability];
                $count = count($risks);
                $score = $probability * $impact;

                // Determine color based on score
                $color = $this->getRiskColor($score);

                $heatMapData[] = [
                    'x' => $probability,
                    'y' => $impact,
                    'count' => $count,
                    'score' => $score,
                    'color' => $color,
                    'risks' => $risks
                ];
            }
        }

        return new JsonResponse([
            'matrix' => $heatMapData,
            'total_risks' => count($risks)
        ]);
    }
    #[Route('/api/compliance-radar', name: 'app_analytics_compliance_radar_data', methods: ['GET'])]
    public function getComplianceRadarData(): JsonResponse
    {
        $controls = $this->controlRepository->findAll();

        // Group by Annex (A.5 - A.18)
        $annexGroups = [];
        foreach ($controls as $control) {
            $annex = $this->extractAnnex($control->getControlId());
            if (!isset($annexGroups[$annex])) {
                $annexGroups[$annex] = [
                    'total' => 0,
                    'implemented' => 0
                ];
            }

            $annexGroups[$annex]['total']++;
            if ($control->getImplementationStatus() === 'implemented') {
                $annexGroups[$annex]['implemented']++;
            }
        }

        // Calculate percentages
        $radarData = [];
        foreach ($annexGroups as $annex => $data) {
            $percentage = $data['total'] > 0
                ? round(($data['implemented'] / $data['total']) * 100)
                : 0;

            $radarData[] = [
                'label' => $annex,
                'value' => $percentage,
                'implemented' => $data['implemented'],
                'total' => $data['total']
            ];
        }

        // Sort by annex number
        usort($radarData, fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return new JsonResponse([
            'data' => $radarData,
            'overall_compliance' => $this->calculateOverallCompliance($radarData)
        ]);
    }
    #[Route('/api/trends', name: 'app_analytics_trends_data', methods: ['GET'])]
    public function getTrendsData(Request $request): JsonResponse
    {
        $period = $request->query->get('period', '12'); // months

        // Risk Trend
        $riskTrend = $this->getRiskTrend((int)$period);

        // Asset Trend
        $assetTrend = $this->getAssetTrend((int)$period);

        // Incident Trend
        $incidentTrend = $this->getIncidentTrend((int)$period);

        return new JsonResponse([
            'risks' => $riskTrend,
            'assets' => $assetTrend,
            'incidents' => $incidentTrend
        ]);
    }
    /**
     * Async wrapper around {@see self::exportData()}: dispatches an
     * {@see \App\Job\ExportAnalyticsJob} that writes the analytics CSV
     * (risks / assets / compliance slice) to var/exports/<jobId>.csv in
     * the background and renders a polling progress page with a Download
     * CTA once the worker reports succeeded.
     *
     * The legacy sync GET route is kept for back-compat (bookmarks, links
     * embedded in saved reports). New UI traffic should use this dispatch
     * endpoint to avoid PHP-FPM timeouts on large datasets.
     *
     * Phase 3 of the async admin-jobs rollout.
     */
    #[Route('/export/{type}/dispatch', name: 'app_analytics_export_dispatch', methods: ['POST'])]
    #[IsCsrfTokenValid('analytics_export_dispatch')]
    public function exportDataDispatch(
        string $type,
        \App\Service\Job\JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!in_array($type, ['risks', 'assets', 'compliance'], true)) {
            throw $this->createNotFoundException(sprintf('Unsupported analytics export type "%s".', $type));
        }

        $jobId = $jobStatusService->create('analytics.export', [
            'type' => $type,
            '_label' => $translator->trans('analytics.export.progress_title', [], 'analytics'),
            '_subtitle' => $translator->trans('analytics.export.progress_subtitle', [], 'analytics'),
            '_download_label' => $translator->trans('analytics.export.download_button', [], 'analytics'),
        ]);
        // Patch the download URL once the UUID is known (chicken-and-egg
        // with JobStatusService::create — UUID is minted inside the method).
        $jobStatusService->updatePayload($jobId, [
            '_download_url' => $this->generateUrl('app_analytics_export_download', ['id' => $jobId, 'type' => $type]),
        ]);

        $messageBus->dispatch(new \App\Message\Job\ExecuteJobMessage(
            jobClass: \App\Job\ExportAnalyticsJob::class,
            args: ['type' => $type],
            jobId: $jobId,
        ));

        // PRG: 303 redirect to the shared progress page. Required for Hotwire
        // Turbo — see App\Controller\Admin\DataRepairController::runIntegrityCheck()
        // for the full rationale.
        return $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('app_analytics_dashboard'),
        ], Response::HTTP_SEE_OTHER);
    }

    /**
     * Streams the file produced by {@see \App\Job\ExportAnalyticsJob} and
     * removes it from disk afterwards. The job ID UUID-v4 is the canonical
     * filename stem so we can derive the path without any user-controlled
     * string.
     */
    #[Route('/export/{type}/download/{id}', name: 'app_analytics_export_download', methods: ['GET'])]
    public function exportDownload(
        string $type,
        string $id,
        \App\Service\Job\JobStatusService $jobStatusService,
        KernelInterface $kernel,
        TranslatorInterface $translator,
    ): Response {
        if (!in_array($type, ['risks', 'assets', 'compliance'], true)) {
            throw $this->createNotFoundException('Unsupported analytics export type.');
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw $this->createNotFoundException('Invalid export ID.');
        }
        if (!$jobStatusService->exists($id)) {
            throw $this->createNotFoundException(
                $translator->trans('analytics.export.file_not_found', [], 'analytics'),
            );
        }
        $record = $jobStatusService->read($id);
        if (($record['status'] ?? '') !== 'succeeded') {
            throw $this->createNotFoundException(
                $translator->trans('analytics.export.file_not_found', [], 'analytics'),
            );
        }

        $path = $kernel->getProjectDir() . '/var/exports/' . $id . '.csv';
        if (!is_file($path)) {
            throw $this->createNotFoundException(
                $translator->trans('analytics.export.file_not_found', [], 'analytics'),
            );
        }

        $filename = sprintf('analytics_%s_%s.csv', $type, date('Y-m-d'));

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/api/export/{type}', name: 'app_analytics_export', methods: ['GET'])]
    public function exportData(Request $request, string $type): Response
    {
        $data = [];
        $filename = 'analytics_' . $type . '_' . date('Y-m-d') . '.csv';

        switch ($type) {
            case 'risks':
                $data = $this->exportRisks();
                break;
            case 'assets':
                $data = $this->exportAssets();
                break;
            case 'compliance':
                $data = $this->exportCompliance();
                break;
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        $csv = $this->generateCSV($data);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
    // Helper methods
    private function getRiskColor(int $score): string
    {
        // Score-bands owned by App\Risk\RiskMatrixThresholds (ISO 27001 Cl. 6.1.2 b SSoT).
        return match (RiskMatrixThresholds::classify($score)) {
            'critical' => '#fecaca', // Light Red
            'high'     => '#fed7aa', // Light Orange
            'medium'   => '#fef3c7', // Light Yellow
            default    => '#d1fae5', // Light Green (low)
        };
    }
    private function extractAnnex(string $controlId): string
    {
        // Extract annex from control ID (e.g., "A.5.1" -> "A.5")
        if (preg_match('/^(A\.\d+)/', $controlId, $matches)) {
            return $matches[1];
        }
        return 'Other';
    }
    private function calculateOverallCompliance(array $radarData): float
    {
        if ($radarData === []) {
            return 0;
        }

        $total = 0;
        foreach ($radarData as $item) {
            $total += $item['value'];
        }

        return round($total / count($radarData), 1);
    }
    private function getRiskTrend(int $months): array
    {
        $trend = [];
        $now = new DateTime();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (clone $now)->modify("-{$i} months");
            $monthStart = (clone $date)->modify('first day of this month')->setTime(0, 0);
            $monthEnd = (clone $date)->modify('last day of this month')->setTime(23, 59, 59);

            $risks = $this->riskRepository->findAll();
            $monthRisks = array_filter($risks, fn(Risk $risk): bool => $risk->getCreatedAt() <= $monthEnd);

            // Count by level (using thresholds: 15/8/4)
            $low = count(array_filter($monthRisks, fn(Risk $risk): bool => $risk->getInherentRiskLevel() < 4));
            $medium = count(array_filter($monthRisks, fn(Risk $risk): bool => $risk->getInherentRiskLevel() >= 4 && $risk->getInherentRiskLevel() < 8));
            $high = count(array_filter($monthRisks, fn(Risk $risk): bool => $risk->getInherentRiskLevel() >= 8 && $risk->getInherentRiskLevel() < 15));
            $critical = count(array_filter($monthRisks, fn(Risk $risk): bool => $risk->getInherentRiskLevel() >= 15));

            $trend[] = [
                'month' => $date->format('M Y'),
                'low' => $low,
                'medium' => $medium,
                'high' => $high,
                'critical' => $critical,
                'total' => count($monthRisks)
            ];
        }

        return $trend;
    }
    private function getAssetTrend(int $months): array
    {
        $trend = [];
        $now = new DateTime();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (clone $now)->modify("-{$i} months");
            $monthEnd = (clone $date)->modify('last day of this month')->setTime(23, 59, 59);

            $assets = $this->assetRepository->findAll();
            $monthAssets = array_filter($assets, fn(Asset $asset): bool => $asset->getCreatedAt() <= $monthEnd);

            $trend[] = [
                'month' => $date->format('M Y'),
                'count' => count($monthAssets)
            ];
        }

        return $trend;
    }
    private function getIncidentTrend(int $months): array
    {
        $trend = [];
        $now = new DateTime();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (clone $now)->modify("-{$i} months");
            $monthStart = (clone $date)->modify('first day of this month')->setTime(0, 0);
            $monthEnd = (clone $date)->modify('last day of this month')->setTime(23, 59, 59);

            $incidents = $this->incidentRepository->findAll();
            $monthIncidents = array_filter($incidents, function(Incident $incident) use ($monthStart, $monthEnd): bool {
                $occuredAt = $incident->getOccurredAt();
                return $occuredAt >= $monthStart && $occuredAt <= $monthEnd;
            });

            // Count by severity
            $low = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === IncidentSeverity::Low));
            $medium = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === IncidentSeverity::Medium));
            $high = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === IncidentSeverity::High));
            $critical = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === IncidentSeverity::Critical));

            $trend[] = [
                'month' => $date->format('M Y'),
                'low' => $low,
                'medium' => $medium,
                'high' => $high,
                'critical' => $critical,
                'total' => count($monthIncidents)
            ];
        }

        return $trend;
    }
    private function exportRisks(): array
    {
        $risks = $this->riskRepository->findAll();
        $data = [
            ['ID', 'Title', 'Probability', 'Impact', 'Risk Level', 'Status', 'Created At']
        ];

        foreach ($risks as $risk) {
            $data[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getProbability(),
                $risk->getImpact(),
                $risk->getInherentRiskLevel(),
                $risk->getStatus(),
                $risk->getCreatedAt()->format('Y-m-d')
            ];
        }

        return $data;
    }
    private function exportAssets(): array
    {
        $assets = $this->assetRepository->findAll();
        $data = [
            ['ID', 'Name', 'Type', 'Criticality', 'Owner', 'Created At']
        ];

        foreach ($assets as $asset) {
            $data[] = [
                $asset->getId(),
                $asset->getName(),
                $asset->getAssetType(),
                $asset->getConfidentialityValue() . '/' . $asset->getIntegrityValue() . '/' . $asset->getAvailabilityValue(),
                $asset->getOwner() ?? 'N/A',
                $asset->getCreatedAt()->format('Y-m-d')
            ];
        }

        return $data;
    }
    // ========== Phase 7B: New API Endpoints ==========

    /**
     * API: Framework Comparison Data
     */
    #[Route('/api/frameworks/comparison', name: 'app_analytics_api_framework_comparison', methods: ['GET'])]
    public function getFrameworkComparisonData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getFrameworkComparison());
    }

    /**
     * API: Framework Overlap Data
     */
    #[Route('/api/frameworks/overlap', name: 'app_analytics_api_framework_overlap', methods: ['GET'])]
    public function getFrameworkOverlapData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getFrameworkOverlap());
    }

    /**
     * API: Control Coverage Matrix
     */
    #[Route('/api/controls/coverage', name: 'app_analytics_api_control_coverage', methods: ['GET'])]
    public function getControlCoverageData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getControlCoverageMatrix());
    }

    /**
     * API: Gap Analysis Data
     */
    #[Route('/api/compliance/gaps', name: 'app_analytics_api_compliance_gaps', methods: ['GET'])]
    public function getGapAnalysisData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getGapAnalysis());
    }

    /**
     * API: Transitive Compliance Data
     */
    #[Route('/api/compliance/transitive', name: 'app_analytics_api_transitive_compliance', methods: ['GET'])]
    public function getTransitiveComplianceData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getTransitiveCompliance());
    }

    /**
     * API: Compliance Roadmap Data
     */
    #[Route('/api/compliance/roadmap', name: 'app_analytics_api_compliance_roadmap', methods: ['GET'])]
    public function getComplianceRoadmapData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getComplianceRoadmap());
    }

    /**
     * API: Control Effectiveness Data
     */
    #[Route('/api/controls/effectiveness', name: 'app_analytics_api_control_effectiveness', methods: ['GET'])]
    public function getControlEffectivenessData(): JsonResponse
    {
        return new JsonResponse($this->controlEffectivenessService->getEffectivenessDashboard());
    }

    /**
     * API: Control Category Performance
     */
    #[Route('/api/controls/categories', name: 'app_analytics_api_control_categories', methods: ['GET'])]
    public function getControlCategoryData(): JsonResponse
    {
        return new JsonResponse($this->controlEffectivenessService->getCategoryPerformance());
    }

    /**
     * API: Control-Risk Matrix
     */
    #[Route('/api/controls/risk-matrix', name: 'app_analytics_api_control_risk_matrix', methods: ['GET'])]
    public function getControlRiskMatrixData(): JsonResponse
    {
        return new JsonResponse($this->controlEffectivenessService->getControlRiskMatrix());
    }

    /**
     * API: Risk Forecast Data
     */
    #[Route('/api/risk/forecast', name: 'app_analytics_api_risk_forecast', methods: ['GET'])]
    public function getRiskForecastData(Request $request): JsonResponse
    {
        $months = (int) $request->query->get('months', 6);
        return new JsonResponse($this->riskForecastService->getRiskForecast($months));
    }

    /**
     * API: Risk Velocity Data
     */
    #[Route('/api/risk/velocity', name: 'app_analytics_api_risk_velocity', methods: ['GET'])]
    public function getRiskVelocityData(): JsonResponse
    {
        return new JsonResponse($this->riskForecastService->getRiskVelocity());
    }

    /**
     * API: Risk Appetite Compliance
     */
    #[Route('/api/risk/appetite', name: 'app_analytics_api_risk_appetite', methods: ['GET'])]
    public function getRiskAppetiteData(): JsonResponse
    {
        return new JsonResponse($this->riskForecastService->getRiskAppetiteCompliance());
    }

    /**
     * API: Anomaly Detection
     */
    #[Route('/api/risk/anomalies', name: 'app_analytics_api_anomalies', methods: ['GET'])]
    public function getAnomalyData(): JsonResponse
    {
        return new JsonResponse($this->riskForecastService->getAnomalyDetection());
    }

    /**
     * API: Asset Incident Probability
     */
    #[Route('/api/assets/incident-probability', name: 'app_analytics_api_asset_probability', methods: ['GET'])]
    public function getAssetProbabilityData(): JsonResponse
    {
        return new JsonResponse($this->assetCriticalityService->getAssetIncidentProbability());
    }

    /**
     * API: Asset Criticality Dashboard
     */
    #[Route('/api/assets/criticality', name: 'app_analytics_api_asset_criticality', methods: ['GET'])]
    public function getAssetCriticalityData(): JsonResponse
    {
        return new JsonResponse($this->assetCriticalityService->getCriticalityDashboard());
    }

    /**
     * API: Asset Vulnerability Matrix
     */
    #[Route('/api/assets/vulnerability-matrix', name: 'app_analytics_api_vulnerability_matrix', methods: ['GET'])]
    public function getVulnerabilityMatrixData(): JsonResponse
    {
        return new JsonResponse($this->assetCriticalityService->getVulnerabilityMatrix());
    }

    /**
     * API: Asset Type Analysis
     */
    #[Route('/api/assets/type-analysis', name: 'app_analytics_api_type_analysis', methods: ['GET'])]
    public function getTypeAnalysisData(): JsonResponse
    {
        return new JsonResponse($this->assetCriticalityService->getTypeAnalysis());
    }

    /**
     * API: Supply Chain Risk
     */
    #[Route('/api/assets/supply-chain', name: 'app_analytics_api_supply_chain', methods: ['GET'])]
    public function getSupplyChainRiskData(): JsonResponse
    {
        return new JsonResponse($this->assetCriticalityService->getSupplyChainRisk());
    }

    /**
     * API: Executive Summary
     */
    #[Route('/api/executive-summary', name: 'app_analytics_api_executive_summary', methods: ['GET'])]
    public function getExecutiveSummaryData(): JsonResponse
    {
        return new JsonResponse($this->complianceAnalyticsService->getExecutiveSummary());
    }

    // ========== Private Helper Methods ==========

    private function exportCompliance(): array
    {
        $controls = $this->controlRepository->findAll();
        $data = [
            ['Control ID', 'Name', 'Status', 'Implementation Date']
        ];

        foreach ($controls as $control) {
            $data[] = [
                $control->getControlId(),
                $control->getName(),
                $control->getImplementationStatus(),
                $control->getLastReviewDate() ? $control->getLastReviewDate()->format('Y-m-d') : 'N/A'
            ];
        }

        return $data;
    }

    private function generateCSV(array $data): string
    {
        $output = fopen('php://temp', 'r+');

        foreach ($data as $row) {
            fputcsv($output, array_map([$this, 'sanitizeCsvValue'], $row), escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Prefixes values starting with =, +, -, @, TAB or CR with a single quote.
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
