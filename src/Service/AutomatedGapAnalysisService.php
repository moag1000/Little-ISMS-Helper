<?php

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\MappingGapItem;

/**
 * Service for automated gap analysis between compliance requirements
 *
 * Identifies specific gaps and missing elements that prevent full compliance mapping
 */
class AutomatedGapAnalysisService
{
    private const CONFIDENCE_THRESHOLD_HIGH = 80;
    private const CONFIDENCE_THRESHOLD_MEDIUM = 60;

    /**
     * Analyze mapping and generate gap items
     *
     * @param ComplianceMapping $mapping
     * @param array $analysisResults Results from MappingQualityAnalysisService
     * @return array Array of MappingGapItem entities
     */
    public function analyzeGaps(ComplianceMapping $mapping, array $analysisResults): array
    {
        $gapItems = [];

        $source = $mapping->getSourceRequirement();
        $target = $mapping->getTargetRequirement();

        $sourceKeywords = $analysisResults['extracted_keywords']['source'] ?? [];
        $targetKeywords = $analysisResults['extracted_keywords']['target'] ?? [];

        // 1. Identify missing keywords/concepts
        $missingKeywords = array_diff($targetKeywords, $sourceKeywords);
        if (!empty($missingKeywords)) {
            $gap = $this->createMissingKeywordsGap($mapping, $missingKeywords, $target);
            if ($gap) {
                $gapItems[] = $gap;
            }
        }

        // 2. Identify partial coverage issues
        $textualSimilarity = $analysisResults['textual_similarity'] ?? 0;
        if ($textualSimilarity > 0.3 && $textualSimilarity < 0.7) {
            $gap = $this->createPartialCoverageGap($mapping, $textualSimilarity);
            if ($gap) {
                $gapItems[] = $gap;
            }
        }

        // 3. Identify scope differences
        $structuralSimilarity = $analysisResults['structural_similarity'] ?? 0;
        if ($structuralSimilarity < 0.5) {
            $gap = $this->createScopeDifferenceGap($mapping, $source, $target);
            if ($gap) {
                $gapItems[] = $gap;
            }
        }

        // 4. Identify additional requirements in target
        $gap = $this->identifyAdditionalRequirements($mapping, $source, $target, $targetKeywords, $sourceKeywords);
        if ($gap) {
            $gapItems[] = $gap;
        }

        // 5. Check for evidence gaps
        if ($this->hasEvidenceGap($mapping, $source, $target)) {
            $gap = $this->createEvidenceGap($mapping);
            if ($gap) {
                $gapItems[] = $gap;
            }
        }

        return $gapItems;
    }

    /**
     * Create gap item for missing keywords/concepts
     */
    private function createMissingKeywordsGap(
        ComplianceMapping $mapping,
        array $missingKeywords,
        ComplianceRequirement $target
    ): ?MappingGapItem {
        if (empty($missingKeywords)) {
            return null;
        }

        $gap = new MappingGapItem();
        $gap->setMapping($mapping);
        $gap->setGapType('missing_control');

        // Categorize missing keywords by importance
        $criticalKeywords = ['encryption', 'authentication', 'authorization', 'audit', 'logging'];
        $highKeywords = ['access control', 'monitoring', 'backup', 'incident', 'vulnerability'];

        $criticalMissing = array_intersect($missingKeywords, $criticalKeywords);
        $highMissing = array_intersect($missingKeywords, $highKeywords);

        if (!empty($criticalMissing)) {
            $gap->setPriority('critical');
            $impact = 30;
        } elseif (!empty($highMissing)) {
            $gap->setPriority('high');
            $impact = 20;
        } elseif (count($missingKeywords) > 5) {
            $gap->setPriority('high');
            $impact = 25;
        } else {
            $gap->setPriority('medium');
            $impact = 15;
        }

        $keywordList = implode(', ', array_slice($missingKeywords, 0, 10));
        $description = sprintf(
            'Das Source-Requirement deckt folgende Konzepte nicht ab, die im Target-Requirement gefordert werden: %s. ' .
            'Diese Aspekte müssen zusätzlich implementiert werden, um vollständige Compliance zu erreichen.',
            $keywordList
        );

        $gap->setDescription($description);
        $gap->setMissingKeywords(array_values($missingKeywords));
        $gap->setPercentageImpact($impact);
        $gap->setConfidence($this->calculateGapConfidence(count($missingKeywords), 'keyword'));

        // Generate recommendations
        $recommendations = $this->generateKeywordRecommendations($missingKeywords, $target);
        $gap->setRecommendedAction($recommendations);

        // Estimate effort (1-2 hours per missing critical concept)
        $effort = count($criticalMissing) * 2 + count($highMissing) * 1 + (count($missingKeywords) - count($criticalMissing) - count($highMissing)) * 0.5;
        $gap->setEstimatedEffort((int) ceil($effort));

        $gap->setIdentificationSource('algorithm');
        $gap->setStatus('identified');

        return $gap;
    }

    /**
     * Create gap item for partial coverage
     */
    private function createPartialCoverageGap(
        ComplianceMapping $mapping,
        float $textualSimilarity
    ): ?MappingGapItem {
        $gap = new MappingGapItem();
        $gap->setMapping($mapping);
        $gap->setGapType('partial_coverage');

        $coveragePercent = (int) round($textualSimilarity * 100);

        $description = sprintf(
            'Das Source-Requirement deckt das Target-Requirement nur zu ca. %d%% ab. ' .
            'Die inhaltliche Übereinstimmung ist unvollständig. ' .
            'Es sind zusätzliche Maßnahmen erforderlich, um die fehlenden Aspekte abzudecken.',
            $coveragePercent
        );

        $gap->setDescription($description);
        $gap->setPriority($coveragePercent < 50 ? 'high' : 'medium');
        $gap->setPercentageImpact((int) round((1 - $textualSimilarity) * 30));
        $gap->setConfidence($this->calculateGapConfidence($textualSimilarity, 'similarity'));

        $gap->setRecommendedAction(
            'Detaillierte Gap-Analyse durchführen: Target-Requirement mit Source-Requirement vergleichen und ' .
            'spezifische fehlende Aspekte identifizieren. Anschließend ergänzende Kontrollen implementieren oder ' .
            'bestehende Kontrollen erweitern.'
        );

        $gap->setEstimatedEffort((int) round((1 - $textualSimilarity) * 10));
        $gap->setIdentificationSource('algorithm');
        $gap->setStatus('identified');

        return $gap;
    }

    /**
     * Create gap item for scope differences
     */
    private function createScopeDifferenceGap(
        ComplianceMapping $mapping,
        ComplianceRequirement $source,
        ComplianceRequirement $target
    ): ?MappingGapItem {
        $gap = new MappingGapItem();
        $gap->setMapping($mapping);
        $gap->setGapType('scope_difference');

        $sourceCategory = $source->getCategory() ?? 'Unbekannt';
        $targetCategory = $target->getCategory() ?? 'Unbekannt';

        $description = sprintf(
            'Scope-Unterschied erkannt: Source-Requirement (Kategorie: %s) und Target-Requirement (Kategorie: %s) ' .
            'haben unterschiedliche Schwerpunkte oder Anwendungsbereiche. ' .
            'Eine Eins-zu-Eins-Übertragung ist nicht vollständig möglich.',
            $sourceCategory,
            $targetCategory
        );

        $gap->setDescription($description);
        $gap->setPriority('medium');
        $gap->setPercentageImpact(15);
        $gap->setConfidence($this->calculateGapConfidence(0.3, 'scope'));

        $gap->setRecommendedAction(
            'Prüfen, ob die unterschiedlichen Scopes durch Kombination mehrerer Source-Requirements abgedeckt werden können, ' .
            'oder ob zusätzliche spezifische Kontrollen für den Target-Scope erforderlich sind.'
        );

        $gap->setEstimatedEffort(4);
        $gap->setIdentificationSource('algorithm');
        $gap->setStatus('identified');

        return $gap;
    }

    /**
     * Identify additional requirements in target
     */
    private function identifyAdditionalRequirements(
        ComplianceMapping $mapping,
        ComplianceRequirement $source,
        ComplianceRequirement $target,
        array $targetKeywords,
        array $sourceKeywords
    ): ?MappingGapItem {
        // Check if target has significantly more content
        $targetText = $this->getRequirementText($target);
        $sourceText = $this->getRequirementText($source);

        $targetLength = strlen($targetText);
        $sourceLength = strlen($sourceText);

        // If target is significantly longer (>50% more) and has unique keywords
        if ($targetLength > $sourceLength * 1.5 && count(array_diff($targetKeywords, $sourceKeywords)) > 5) {
            $gap = new MappingGapItem();
            $gap->setMapping($mapping);
            $gap->setGapType('additional_requirement');

            $uniqueKeywords = array_diff($targetKeywords, $sourceKeywords);

            $description = sprintf(
                'Das Target-Requirement hat zusätzliche Anforderungen, die über das Source-Requirement hinausgehen. ' .
                'Es wurden %d zusätzliche Konzepte identifiziert, die im Source nicht vorhanden sind.',
                count($uniqueKeywords)
            );

            $gap->setDescription($description);
            $gap->setMissingKeywords(array_values(array_slice($uniqueKeywords, 0, 20)));
            $gap->setPriority('high');
            $gap->setPercentageImpact(25);
            $gap->setConfidence($this->calculateGapConfidence(count($uniqueKeywords), 'additional'));

            $gap->setRecommendedAction(
                'Die zusätzlichen Anforderungen des Target-Requirements müssen separat implementiert werden. ' .
                'Prüfen Sie, ob andere Requirements des Source-Frameworks diese Aspekte abdecken, ' .
                'oder ob neue Kontrollen erforderlich sind.'
            );

            $gap->setEstimatedEffort((int) ceil(count($uniqueKeywords) * 0.5));
            $gap->setIdentificationSource('algorithm');
            $gap->setStatus('identified');

            return $gap;
        }

        return null;
    }

    /**
     * Check if there's an evidence gap
     */
    private function hasEvidenceGap(
        ComplianceMapping $mapping,
        ComplianceRequirement $source,
        ComplianceRequirement $target
    ): bool {
        // Evidence gap exists if:
        // - Mapping percentage is high (>80)
        // - But textual similarity is medium (0.5-0.7)
        // - Suggesting control exists but documentation is weak

        $mappingPercentage = $mapping->getMappingPercentage();
        $textualSimilarity = $mapping->getTextualSimilarity() ?? 0;

        return $mappingPercentage > 80 && $textualSimilarity > 0.5 && $textualSimilarity < 0.7;
    }

    /**
     * Create evidence gap
     */
    private function createEvidenceGap(ComplianceMapping $mapping): ?MappingGapItem {
        $gap = new MappingGapItem();
        $gap->setMapping($mapping);
        $gap->setGapType('evidence_gap');

        $description = 'Die Kontrolle scheint grundsätzlich vorhanden zu sein, jedoch fehlt möglicherweise ' .
            'vollständige Dokumentation oder Nachweise (Evidenz) für die Umsetzung. ' .
            'Die Mapping-Percentage ist hoch, aber die textuelle Übereinstimmung deutet auf Lücken hin.';

        $gap->setDescription($description);
        $gap->setPriority('medium');
        $gap->setPercentageImpact(10);
        $gap->setConfidence($this->calculateGapConfidence(0.6, 'evidence'));

        $gap->setRecommendedAction(
            'Dokumentation vervollständigen: Erstellen Sie ausführliche Beschreibungen der implementierten Kontrollen, ' .
            'sammeln Sie Nachweise (Screenshots, Policies, Protokolle) und dokumentieren Sie die Umsetzung gemäß ' .
            'den Anforderungen des Target-Frameworks.'
        );

        $gap->setEstimatedEffort(3);
        $gap->setIdentificationSource('algorithm');
        $gap->setStatus('identified');

        return $gap;
    }

    /**
     * Calculate confidence for a gap identification
     */
    private function calculateGapConfidence($value, string $type): int
    {
        $confidence = 50; // Base confidence

        switch ($type) {
            case 'keyword':
                // More missing keywords = higher confidence this is a real gap
                $count = is_array($value) ? count($value) : $value;
                if ($count > 10) {
                    $confidence = 85;
                } elseif ($count > 5) {
                    $confidence = 75;
                } elseif ($count > 2) {
                    $confidence = 65;
                } else {
                    $confidence = 50;
                }
                break;

            case 'similarity':
                // Moderate similarity = high confidence in partial gap
                if ($value > 0.4 && $value < 0.6) {
                    $confidence = 80;
                } elseif ($value > 0.3 && $value < 0.7) {
                    $confidence = 70;
                } else {
                    $confidence = 60;
                }
                break;

            case 'scope':
                $confidence = 65; // Medium confidence for scope differences
                break;

            case 'additional':
                $count = is_array($value) ? count($value) : $value;
                if ($count > 8) {
                    $confidence = 80;
                } elseif ($count > 4) {
                    $confidence = 70;
                } else {
                    $confidence = 60;
                }
                break;

            case 'evidence':
                $confidence = 70;
                break;
        }

        return max(0, min(100, $confidence));
    }

    /**
     * Generate recommendations for missing keywords
     */
    private function generateKeywordRecommendations(array $missingKeywords, ComplianceRequirement $target): string
    {
        $recommendations = ['Folgende Maßnahmen werden empfohlen:'];

        // Categorize recommendations
        $categories = [
            'encryption' => 'Implementierung von Verschlüsselungskontrollen (z.B. TLS, Datenverschlüsselung at rest)',
            'authentication' => 'Implementierung von Authentifizierungsmechanismen (z.B. MFA, SSO)',
            'authorization' => 'Implementierung von Autorisierungskontrollen (z.B. RBAC, Least Privilege)',
            'access control' => 'Implementierung von Zugangskontrollen und Access Management',
            'audit' => 'Einrichtung von Audit-Logging und Review-Prozessen',
            'logging' => 'Implementierung umfassender Log-Management-Lösungen',
            'monitoring' => 'Einrichtung von kontinuierlichem Monitoring und Alerting',
            'backup' => 'Implementierung von Backup- und Recovery-Prozeduren',
            'incident' => 'Etablierung von Incident Response Prozessen',
            'vulnerability' => 'Einrichtung von Vulnerability Management und Patching',
            'network' => 'Implementierung von Netzwerksicherheitskontrollen (Firewall, Segmentierung)',
            'risk' => 'Durchführung von Risk Assessments und Implementierung von Risk Treatment',
        ];

        $added = [];
        foreach ($missingKeywords as $keyword) {
            foreach ($categories as $key => $recommendation) {
                if (stripos($keyword, $key) !== false && !in_array($recommendation, $added, true)) {
                    $recommendations[] = '- ' . $recommendation;
                    $added[] = $recommendation;
                    break;
                }
            }
        }

        if (count($recommendations) === 1) {
            $recommendations[] = '- Detaillierte Analyse der fehlenden Konzepte durchführen';
            $recommendations[] = '- Mit Subject Matter Experts die Anforderungen klären';
            $recommendations[] = '- Implementierungsplan für die fehlenden Kontrollen erstellen';
        }

        return implode("\n", $recommendations);
    }

    /**
     * Get requirement text (title + description)
     */
    private function getRequirementText(ComplianceRequirement $requirement): string
    {
        $parts = [];

        if ($title = $requirement->getTitle()) {
            $parts[] = $title;
        }

        if ($description = $requirement->getDescription()) {
            $parts[] = $description;
        }

        return implode(' ', $parts);
    }

    /**
     * Calculate total gap impact for a mapping
     */
    public function calculateTotalGapImpact(array $gapItems): int
    {
        $totalImpact = 0;

        foreach ($gapItems as $gap) {
            $totalImpact += $gap->getPercentageImpact();
        }

        return min(100, $totalImpact);
    }

    /**
     * Get gap summary statistics
     */
    public function getGapSummary(array $gapItems): array
    {
        $summary = [
            'total_gaps' => count($gapItems),
            'by_type' => [],
            'by_priority' => [],
            'total_impact' => $this->calculateTotalGapImpact($gapItems),
            'total_effort' => 0,
            'high_confidence_gaps' => 0,
        ];

        foreach ($gapItems as $gap) {
            // By type
            $type = $gap->getGapType();
            if (!isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = 0;
            }
            $summary['by_type'][$type]++;

            // By priority
            $priority = $gap->getPriority();
            if (!isset($summary['by_priority'][$priority])) {
                $summary['by_priority'][$priority] = 0;
            }
            $summary['by_priority'][$priority]++;

            // Total effort
            $summary['total_effort'] += $gap->getEstimatedEffort() ?? 0;

            // High confidence gaps
            if ($gap->getConfidence() >= self::CONFIDENCE_THRESHOLD_HIGH) {
                $summary['high_confidence_gaps']++;
            }
        }

        return $summary;
    }
}
