<?php

namespace App\Service;

use App\Entity\Risk;
use App\Repository\RiskRepository;

/**
 * Risk Matrix Service
 *
 * Provides risk assessment matrix generation and visualization for ISO 27001 compliance.
 * Implements a 5x5 risk matrix (likelihood × impact) with automatic risk level calculation.
 *
 * Features:
 * - Risk matrix generation with visual representation
 * - Automatic risk level calculation (critical, high, medium, low)
 * - Statistical analysis and grouping by risk level
 * - Chart.js heatmap data generation
 * - Localized likelihood and impact labels
 * - CSS class and color mappings for visualization
 *
 * Risk Scoring:
 * - Critical: Score >= 20 (Red)
 * - High: Score >= 12 (Orange)
 * - Medium: Score >= 6 (Yellow)
 * - Low: Score < 6 (Green)
 */
class RiskMatrixService
{
    private const MATRIX_SIZE = 5;
    private const LIKELIHOOD_LABELS = [
        1 => 'Sehr selten',
        2 => 'Selten',
        3 => 'Gelegentlich',
        4 => 'Wahrscheinlich',
        5 => 'Sehr wahrscheinlich',
    ];

    private const IMPACT_LABELS = [
        1 => 'Unbedeutend',
        2 => 'Gering',
        3 => 'Moderat',
        4 => 'Hoch',
        5 => 'Kritisch',
    ];

    public function __construct(
        private RiskRepository $riskRepository
    ) {}

    /**
     * Generiert die Risk Assessment Matrix mit allen Risiken
     *
     * @return array{
     *     matrix: array<int, array<int, array<Risk>>>,
     *     labels: array{likelihood: array<int, string>, impact: array<int, string>},
     *     statistics: array{total: int, high: int, medium: int, low: int},
     *     riskLevels: array<int, array<int, string>>
     * }
     */
    public function generateMatrix(?array $risks = null): array
    {
        if ($risks === null) {
            $risks = $this->riskRepository->findAll();
        }

        // Initialize empty matrix
        $matrix = [];
        for ($likelihood = 1; $likelihood <= self::MATRIX_SIZE; $likelihood++) {
            for ($impact = 1; $impact <= self::MATRIX_SIZE; $impact++) {
                $matrix[$likelihood][$impact] = [];
            }
        }

        // Fill matrix with risks
        $statistics = [
            'total' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'critical' => 0,
        ];

        foreach ($risks as $risk) {
            $likelihood = $risk->getProbability() ?? 3;
            $impact = $risk->getImpact() ?? 3;

            // Ensure values are within range
            $likelihood = max(1, min(self::MATRIX_SIZE, $likelihood));
            $impact = max(1, min(self::MATRIX_SIZE, $impact));

            $matrix[$likelihood][$impact][] = $risk;
            $statistics['total']++;

            // Calculate risk level
            $riskScore = $likelihood * $impact;
            if ($riskScore >= 20) {
                $statistics['critical']++;
            } elseif ($riskScore >= 12) {
                $statistics['high']++;
            } elseif ($riskScore >= 6) {
                $statistics['medium']++;
            } else {
                $statistics['low']++;
            }
        }

        // Generate risk level colors for each cell
        $riskLevels = [];
        for ($likelihood = 1; $likelihood <= self::MATRIX_SIZE; $likelihood++) {
            for ($impact = 1; $impact <= self::MATRIX_SIZE; $impact++) {
                $riskLevels[$likelihood][$impact] = $this->calculateRiskLevel($likelihood, $impact);
            }
        }

        return [
            'matrix' => $matrix,
            'labels' => [
                'likelihood' => self::LIKELIHOOD_LABELS,
                'impact' => self::IMPACT_LABELS,
            ],
            'statistics' => $statistics,
            'riskLevels' => $riskLevels,
        ];
    }

    /**
     * Berechnet das Risikolevel basierend auf Likelihood und Impact
     */
    public function calculateRiskLevel(int $likelihood, int $impact): string
    {
        $score = $likelihood * $impact;

        if ($score >= 15) {
            return 'critical'; // Red (15-25)
        } elseif ($score >= 8) {
            return 'high';     // Orange (8-14)
        } elseif ($score >= 4) {
            return 'medium';   // Yellow (4-7)
        } else {
            return 'low';      // Green (1-3)
        }
    }

    /**
     * Gibt die CSS-Klasse für ein Risikolevel zurück
     */
    public function getRiskLevelClass(string $level): string
    {
        return match($level) {
            'critical' => 'risk-critical',
            'high' => 'risk-high',
            'medium' => 'risk-medium',
            'low' => 'risk-low',
            default => 'risk-unknown',
        };
    }

    /**
     * Gibt die Farbe für ein Risikolevel zurück
     */
    public function getRiskLevelColor(string $level): string
    {
        return match($level) {
            'critical' => '#dc3545', // Red
            'high' => '#fd7e14',     // Orange
            'medium' => '#ffc107',   // Yellow
            'low' => '#28a745',      // Green
            default => '#6c757d',    // Gray
        };
    }

    /**
     * Generiert Matrix-Daten für Chart.js Heatmap
     */
    public function generateHeatmapData(?array $risks = null): array
    {
        $matrixData = $this->generateMatrix($risks);
        $data = [];

        for ($likelihood = 1; $likelihood <= self::MATRIX_SIZE; $likelihood++) {
            for ($impact = 1; $impact <= self::MATRIX_SIZE; $impact++) {
                $count = count($matrixData['matrix'][$likelihood][$impact]);
                $level = $matrixData['riskLevels'][$likelihood][$impact];

                $data[] = [
                    'x' => $impact,
                    'y' => $likelihood,
                    'v' => $count,
                    'level' => $level,
                    'color' => $this->getRiskLevelColor($level),
                ];
            }
        }

        return $data;
    }

    /**
     * Gibt Risiko-Statistiken zurück
     */
    public function getRiskStatistics(): array
    {
        $risks = $this->riskRepository->findAll();
        $matrixData = $this->generateMatrix($risks);

        return $matrixData['statistics'];
    }

    /**
     * Gruppiert Risiken nach Risikolevel
     */
    public function getRisksByLevel(): array
    {
        $risks = $this->riskRepository->findAll();
        $grouped = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($risks as $risk) {
            $likelihood = $risk->getProbability() ?? 3;
            $impact = $risk->getImpact() ?? 3;
            $level = $this->calculateRiskLevel($likelihood, $impact);

            $grouped[$level][] = $risk;
        }

        return $grouped;
    }
}
