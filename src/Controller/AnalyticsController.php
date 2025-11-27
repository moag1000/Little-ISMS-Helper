<?php

namespace App\Controller;

use DateTime;
use App\Entity\Risk;
use App\Entity\Asset;
use App\Entity\Incident;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ControlRepository $controlRepository
    ) {}
    #[Route('/analytics', name: 'app_analytics_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('analytics/dashboard.html.twig');
    }
    #[Route('/analytics/api/heat-map', name: 'app_analytics_heat_map_data')]
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
    #[Route('/analytics/api/compliance-radar', name: 'app_analytics_compliance_radar_data')]
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
        usort($radarData, fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return new JsonResponse([
            'data' => $radarData,
            'overall_compliance' => $this->calculateOverallCompliance($radarData)
        ]);
    }
    #[Route('/analytics/api/trends', name: 'app_analytics_trends_data')]
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
    #[Route('/analytics/api/export/{type}', name: 'app_analytics_export')]
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
        if ($score >= 15) {
            return '#fecaca'; // Critical (15-25) - Light Red
        } elseif ($score >= 8) {
            return '#fed7aa'; // High (8-14) - Light Orange
        } elseif ($score >= 4) {
            return '#fef3c7'; // Medium (4-7) - Light Yellow
        } else {
            return '#d1fae5'; // Low (1-3) - Light Green
        }
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
            $low = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === 'low'));
            $medium = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === 'medium'));
            $high = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === 'high'));
            $critical = count(array_filter($monthIncidents, fn(Incident $incident): bool => $incident->getSeverity() === 'critical'));

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
            fputcsv($output, $row, escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
