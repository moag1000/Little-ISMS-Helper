<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Root-cause recommendation texts for ComplianceMappingRepository::analyzeGapRootCauses().
 */
trait GapRootCauseRecommendationsTrait
{
    /**
     * Generate targeted recommendations based on root cause analysis.
     *
     * @param array $rootCauses Classified root causes
     * @param array $categoryPatterns Category-level patterns
     * @return array Actionable recommendations
     */
    private function generateRootCauseRecommendations(array $rootCauses, array $categoryPatterns): array
    {
        $recommendations = [];

        // Recommendation for not-started gaps
        if (count($rootCauses['not_started']) > 0) {
            $recommendations[] = [
                'root_cause' => 'not_started',
                'priority' => 'CRITICAL',
                'count' => count($rootCauses['not_started']),
                'title' => 'Kickstart-Initiative für nicht begonnene Anforderungen',
                'action' => sprintf(
                    '%d Anforderungen (0%% Erfüllung) wurden noch nicht begonnen. Sofortige Ressourcen-Allokation erforderlich.',
                    count($rootCauses['not_started'])
                ),
                'solution' => 'Projekt-Kickoff, Verantwortliche zuweisen, Initiale Assessments durchführen',
                'timeline' => '0-30 Tage',
                'effort' => 'Hoch',
            ];
        }

        // Recommendation for missing controls
        if (count($rootCauses['missing_control']) > 0) {
            $recommendations[] = [
                'root_cause' => 'missing_control',
                'priority' => 'HIGH',
                'count' => count($rootCauses['missing_control']),
                'title' => 'Kontroll-Implementierung priorisieren',
                'action' => sprintf(
                    '%d Anforderungen haben sehr niedrige Erfüllung (<30%%). Kontrollen fehlen oder sind unzureichend.',
                    count($rootCauses['missing_control'])
                ),
                'solution' => 'Control Design Workshop, Policy/Prozedur-Entwicklung, Technische Implementierung',
                'timeline' => '30-90 Tage',
                'effort' => 'Hoch',
            ];
        }

        // Recommendation for incomplete implementation
        if (count($rootCauses['incomplete_implementation']) > 0) {
            $recommendations[] = [
                'root_cause' => 'incomplete_implementation',
                'priority' => 'MEDIUM',
                'count' => count($rootCauses['incomplete_implementation']),
                'title' => 'Unvollständige Implementierungen abschließen',
                'action' => sprintf(
                    '%d Anforderungen sind teilweise umgesetzt (30-80%%). Lücken schließen und finalisieren.',
                    count($rootCauses['incomplete_implementation'])
                ),
                'solution' => 'Gap-Assessment pro Requirement, fehlende Komponenten identifizieren, Completion Roadmap',
                'timeline' => '60-120 Tage',
                'effort' => 'Mittel',
            ];
        }

        // Recommendation for missing evidence
        if (count($rootCauses['missing_evidence']) > 0) {
            $recommendations[] = [
                'root_cause' => 'missing_evidence',
                'priority' => 'MEDIUM',
                'count' => count($rootCauses['missing_evidence']),
                'title' => 'Evidenz-Sammlung & Dokumentation',
                'action' => sprintf(
                    '%d Anforderungen haben hohe Erfüllung (80-99%%) aber fehlende Dokumentation/Nachweise.',
                    count($rootCauses['missing_evidence'])
                ),
                'solution' => 'Dokumentations-Sprint, Evidenz-Sammlung, Audit-Trail vervollständigen',
                'timeline' => '30-60 Tage',
                'effort' => 'Niedrig',
            ];
        }

        // Category-specific recommendations (top 3 problematic categories)
        $topCategories = array_slice($categoryPatterns, 0, 3, true);
        foreach ($topCategories as $category => $pattern) {
            if ($pattern['count'] >= 3) { // Only if significant (3+ gaps)
                $recommendations[] = [
                    'root_cause' => 'category_cluster',
                    'priority' => 'HIGH',
                    'count' => $pattern['count'],
                    'title' => sprintf('Kategorie-spezifische Initiative: %s', $category),
                    'action' => sprintf(
                        'Kategorie "%s" hat %d Gaps (Ø %s%% Erfüllung). Systematisches Problem erkannt.',
                        $category,
                        $pattern['count'],
                        $pattern['avg_fulfillment']
                    ),
                    'solution' => sprintf(
                        'Dedicated Task Force für "%s", Root Cause: %s',
                        $category,
                        $this->translateRootCause($pattern['dominant_root_cause'])
                    ),
                    'timeline' => '30-90 Tage',
                    'effort' => 'Mittel-Hoch',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Translate root cause key to German description.
     *
     * @param string $rootCause Root cause key
     * @return string German description
     */
    private function translateRootCause(string $rootCause): string
    {
        $translations = [
            'not_started' => 'Noch nicht begonnen',
            'missing_control' => 'Fehlende Kontrolle',
            'incomplete_implementation' => 'Unvollständige Umsetzung',
            'missing_evidence' => 'Fehlende Evidenz',
            'low_priority' => 'Niedrige Priorität',
            'category_cluster' => 'Kategorie-Cluster',
        ];

        return $translations[$rootCause] ?? $rootCause;
    }
}
