<?php

namespace App\Service;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\IncidentRepository;

/**
 * Service für intelligente Risiko-Bewertung mit Datenwiederverwendung
 *
 * Nutzt:
 * - Incidents → Threat Intelligence für Risk Assessment
 * - Control Implementation → Residual Risk Calculation
 * - Historical Data → Probability Estimation
 */
class RiskIntelligenceService
{
    public function __construct(
        private readonly IncidentRepository $incidentRepository
    ) {}

    /**
     * Schlägt neue Risiken basierend auf Incidents vor
     *
     * Threat Intelligence aus realen Vorfällen
     */
    public function suggestRisksFromIncidents(): array
    {
        $incidents = $this->incidentRepository->findAll();
        $suggestions = [];

        foreach ($incidents as $incident) {
            // Wenn ein Incident noch nicht zu einem Risk führte
            $existingRisks = $this->findRelatedRisks();

            if ($existingRisks === []) {
                $suggestions[] = [
                    'incident' => $incident,
                    'suggested_risk' => [
                        'title' => 'Wiederholung: ' . $incident->getTitle(),
                        'description' => 'Basierend auf Vorfall #' . $incident->getIncidentNumber(),
                        'threat' => $this->extractThreatFromIncident($incident),
                        'vulnerability' => $this->extractVulnerabilityFromIncident($incident),
                        'probability' => $this->estimateProbabilityFromIncidents($incident->getCategory()),
                        'impact' => $this->mapSeverityToImpact($incident->getSeverity())
                    ]
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Berechnet Residual Risk unter Berücksichtigung implementierter Controls
     */
    public function calculateResidualRisk(Risk $risk): array
    {
        $inherentRisk = $risk->getInherentRiskLevel();
        $controls = $risk->getControls();

        if ($controls->isEmpty()) {
            // Even with no controls, provide risk-level-based recommendation
            $recommendation = 'Keine Controls zugeordnet. ';
            if ($inherentRisk > 18) {
                $recommendation .= 'Restrisiko kritisch - Controls dringend erforderlich.';
            } elseif ($inherentRisk > 12) {
                $recommendation .= 'Restrisiko moderat - Controls empfohlen.';
            } else {
                $recommendation .= 'Restrisiko akzeptabel, aber Controls zur Verbesserung empfohlen.';
            }

            return [
                'inherent' => $inherentRisk,
                'residual' => $inherentRisk,
                'reduction' => 0,
                'controls_applied' => 0,
                'recommendation' => $recommendation
            ];
        }

        // Berechne Risikoreduktion basierend auf Control-Implementierung
        $totalReduction = 0;
        $implementedControls = 0;

        foreach ($controls as $control) {
            if ($control->getImplementationStatus() === 'implemented') {
                $implementedControls++;
                // Jedes implementierte Control reduziert das Risiko
                $effectiveness = $control->getImplementationPercentage() / 100;
                $totalReduction += (0.3 * $effectiveness); // 30% max Reduktion pro Control
            } elseif ($control->getImplementationStatus() === 'in_progress') {
                $effectiveness = $control->getImplementationPercentage() / 100;
                $totalReduction += (0.15 * $effectiveness); // 15% max für in-progress
            }
        }

        // Risikoreduktion cap bei 80%
        $totalReduction = min($totalReduction, 0.8);

        $residualRisk = (int) round($inherentRisk * (1 - $totalReduction));
        $reduction = $inherentRisk - $residualRisk;

        $recommendation = '';
        if ($implementedControls === 0) {
            $recommendation = 'Controls in Planung. Restrisiko bleibt hoch.';
        } elseif ($residualRisk > 18) {
            $recommendation = 'Restrisiko noch kritisch. Zusätzliche Controls erforderlich.';
        } elseif ($residualRisk > 12) {
            $recommendation = 'Restrisiko moderat. Weitere Controls empfohlen.';
        } else {
            $recommendation = 'Restrisiko akzeptabel.';
        }

        return [
            'inherent' => $inherentRisk,
            'residual' => $residualRisk,
            'reduction' => $reduction,
            'reduction_percentage' => round($totalReduction * 100),
            'controls_applied' => $implementedControls,
            'controls_total' => count($controls),
            'recommendation' => $recommendation
        ];
    }

    /**
     * Identifiziert Controls die bei ähnlichen Incidents geholfen haben
     */
    public function suggestControlsForRisk(Risk $risk): array
    {
        // Finde ähnliche Incidents
        $similarIncidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.category = :category OR i.severity = :severity')
            ->setParameter('category', $this->mapRiskToIncidentCategory($risk))
            ->setParameter('severity', $this->mapImpactToSeverity($risk->getImpact()))
            ->getQuery()
            ->getResult();

        $suggestedControls = [];

        foreach ($similarIncidents as $similarIncident) {
            // Controls die bei diesem Incident zur Behebung führten
            $relatedControls = $similarIncident->getRelatedControls();

            foreach ($relatedControls as $relatedControl) {
                if (!$risk->getControls()->contains($relatedControl)) {
                    $suggestedControls[] = [
                        'control' => $relatedControl,
                        'reason' => sprintf(
                            'Half bei Incident #%s: %s',
                            $similarIncident->getIncidentNumber(),
                            $similarIncident->getTitle()
                        )
                    ];
                }
            }
        }

        return array_slice(array_unique($suggestedControls, SORT_REGULAR), 0, 5);
    }

    /**
     * Analysiert Incident-Trends für proaktives Risk Management
     */
    public function analyzeIncidentTrends(): array
    {
        $incidents = $this->incidentRepository->findAll();

        $trends = [
            'by_category' => [],
            'by_severity' => [],
            'by_month' => [],
            'recurring_patterns' => []
        ];

        foreach ($incidents as $incident) {
            // Category Trend
            $category = $incident->getCategory();
            if (!isset($trends['by_category'][$category])) {
                $trends['by_category'][$category] = 0;
            }
            $trends['by_category'][$category]++;

            // Severity Trend
            $severity = $incident->getSeverity();
            if (!isset($trends['by_severity'][$severity])) {
                $trends['by_severity'][$severity] = 0;
            }
            $trends['by_severity'][$severity]++;

            // Monthly Trend
            $month = $incident->getDetectedAt()->format('Y-m');
            if (!isset($trends['by_month'][$month])) {
                $trends['by_month'][$month] = 0;
            }
            $trends['by_month'][$month]++;
        }

        // Sortiere nach Häufigkeit
        arsort($trends['by_category']);
        arsort($trends['by_severity']);
        ksort($trends['by_month']);

        return $trends;
    }

    // Helper Methods

    private function findRelatedRisks(): array
    {
        // Vereinfachte Logik - kann erweitert werden
        return [];
    }

    private function extractThreatFromIncident(Incident $incident): string
    {
        return $incident->getRootCause() ?? 'Bedrohung aus Vorfall: ' . $incident->getCategory();
    }

    private function extractVulnerabilityFromIncident(Incident $incident): string
    {
        return 'Schwachstelle identifiziert durch Vorfall #' . $incident->getIncidentNumber();
    }

    private function estimateProbabilityFromIncidents(string $category): int
    {
        $count = $this->incidentRepository->count(['category' => $category]);

        if ($count > 5) {
            return 5;
        }
        if ($count > 3) {
            return 4;
        }
        if ($count > 1) {
            return 3;
        }
        if ($count > 0) {
            return 2;
        }
        return 1;
    }

    private function mapSeverityToImpact(string $severity): int
    {
        return match($severity) {
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            default => 1
        };
    }

    private function mapRiskToIncidentCategory(Risk $risk): string
    {
        // Vereinfachte Mapping-Logik
        if (str_contains(strtolower((string) $risk->getDescription()), 'cyber')) {
            return 'cyber_attack';
        }
        return 'other';
    }

    private function mapImpactToSeverity(int $impact): string
    {
        return match(true) {
            $impact >= 5 => 'critical',
            $impact >= 4 => 'high',
            $impact >= 3 => 'medium',
            default => 'low'
        };
    }
}
