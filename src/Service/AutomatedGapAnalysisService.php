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
    private const int CONFIDENCE_THRESHOLD_HIGH = 80;

    /**
     * Analyze mapping and generate gap items
     *
     * @param array $analysisResults Results from MappingQualityAnalysisService
     * @return array Array of MappingGapItem entities
     */
    public function analyzeGaps(ComplianceMapping $complianceMapping, array $analysisResults): array
    {
        $gapItems = [];

        $source = $complianceMapping->getSourceRequirement();
        $target = $complianceMapping->getTargetRequirement();

        $sourceKeywords = $analysisResults['extracted_keywords']['source'] ?? [];
        $targetKeywords = $analysisResults['extracted_keywords']['target'] ?? [];

        // 1. Identify missing keywords/concepts
        $missingKeywords = array_diff($targetKeywords, $sourceKeywords);
        if ($missingKeywords !== []) {
            $gap = $this->createMissingKeywordsGap($complianceMapping, $missingKeywords);
            if ($gap instanceof MappingGapItem) {
                $gapItems[] = $gap;
            }
        }

        // 2. Identify partial coverage issues
        $textualSimilarity = $analysisResults['textual_similarity'] ?? 0;
        if ($textualSimilarity > 0.3 && $textualSimilarity < 0.7) {
            $gap = $this->createPartialCoverageGap($complianceMapping, $textualSimilarity);
            if ($gap instanceof MappingGapItem) {
                $gapItems[] = $gap;
            }
        }

        // 3. Identify scope differences
        $structuralSimilarity = $analysisResults['structural_similarity'] ?? 0;
        if ($structuralSimilarity < 0.5) {
            $gap = $this->createScopeDifferenceGap($complianceMapping, $source, $target);
            if ($gap instanceof MappingGapItem) {
                $gapItems[] = $gap;
            }
        }

        // 4. Identify additional requirements in target
        $gap = $this->identifyAdditionalRequirements($complianceMapping, $source, $target, $targetKeywords, $sourceKeywords);
        if ($gap instanceof MappingGapItem) {
            $gapItems[] = $gap;
        }

        // 5. Check for evidence gaps
        if ($this->hasEvidenceGap($complianceMapping)) {
            $gap = $this->createEvidenceGap($complianceMapping);
            if ($gap instanceof MappingGapItem) {
                $gapItems[] = $gap;
            }
        }

        return $gapItems;
    }

    /**
     * Create gap item for missing keywords/concepts
     */
    private function createMissingKeywordsGap(
        ComplianceMapping $complianceMapping,
        array $missingKeywords
    ): ?MappingGapItem {
        if ($missingKeywords === []) {
            return null;
        }

        $mappingGapItem = new MappingGapItem();
        $mappingGapItem->setMapping($complianceMapping);
        $mappingGapItem->setGapType('missing_control');

        // Categorize missing keywords by importance
        $criticalKeywords = ['encryption', 'authentication', 'authorization', 'audit', 'logging'];
        $highKeywords = ['access control', 'monitoring', 'backup', 'incident', 'vulnerability'];

        $criticalMissing = array_intersect($missingKeywords, $criticalKeywords);
        $highMissing = array_intersect($missingKeywords, $highKeywords);

        if ($criticalMissing !== []) {
            $mappingGapItem->setPriority('critical');
            $impact = 30;
        } elseif ($highMissing !== []) {
            $mappingGapItem->setPriority('high');
            $impact = 20;
        } elseif (count($missingKeywords) > 5) {
            $mappingGapItem->setPriority('high');
            $impact = 25;
        } else {
            $mappingGapItem->setPriority('medium');
            $impact = 15;
        }

        $keywordList = implode(', ', array_slice($missingKeywords, 0, 10));
        $description = sprintf(
            'Das Source-Requirement deckt folgende Konzepte nicht ab, die im Target-Requirement gefordert werden: %s. ' .
            'Diese Aspekte müssen zusätzlich implementiert werden, um vollständige Compliance zu erreichen.',
            $keywordList
        );

        $mappingGapItem->setDescription($description);
        $mappingGapItem->setMissingKeywords(array_values($missingKeywords));
        $mappingGapItem->setPercentageImpact($impact);
        $mappingGapItem->setConfidence($this->calculateGapConfidence(count($missingKeywords), 'keyword'));

        // Generate recommendations
        $recommendations = $this->generateKeywordRecommendations($missingKeywords);
        $mappingGapItem->setRecommendedAction($recommendations);

        // Estimate effort (1-2 hours per missing critical concept)
        $effort = count($criticalMissing) * 2 + count($highMissing) + (count($missingKeywords) - count($criticalMissing) - count($highMissing)) * 0.5;
        $mappingGapItem->setEstimatedEffort((int) ceil($effort));

        $mappingGapItem->setIdentificationSource('algorithm');
        $mappingGapItem->setStatus('identified');

        return $mappingGapItem;
    }

    /**
     * Create gap item for partial coverage
     */
    private function createPartialCoverageGap(
        ComplianceMapping $complianceMapping,
        float $textualSimilarity
    ): MappingGapItem {
        $mappingGapItem = new MappingGapItem();
        $mappingGapItem->setMapping($complianceMapping);
        $mappingGapItem->setGapType('partial_coverage');

        $coveragePercent = (int) round($textualSimilarity * 100);

        $description = sprintf(
            'Das Source-Requirement deckt das Target-Requirement nur zu ca. %d%% ab. ' .
            'Die inhaltliche Übereinstimmung ist unvollständig. ' .
            'Es sind zusätzliche Maßnahmen erforderlich, um die fehlenden Aspekte abzudecken.',
            $coveragePercent
        );

        $mappingGapItem->setDescription($description);
        $mappingGapItem->setPriority($coveragePercent < 50 ? 'high' : 'medium');
        $mappingGapItem->setPercentageImpact((int) round((1 - $textualSimilarity) * 30));
        $mappingGapItem->setConfidence($this->calculateGapConfidence($textualSimilarity, 'similarity'));

        $mappingGapItem->setRecommendedAction(
            'Detaillierte Gap-Analyse durchführen: Target-Requirement mit Source-Requirement vergleichen und ' .
            'spezifische fehlende Aspekte identifizieren. Anschließend ergänzende Kontrollen implementieren oder ' .
            'bestehende Kontrollen erweitern.'
        );

        $mappingGapItem->setEstimatedEffort((int) round((1 - $textualSimilarity) * 10));
        $mappingGapItem->setIdentificationSource('algorithm');
        $mappingGapItem->setStatus('identified');

        return $mappingGapItem;
    }

    /**
     * Create gap item for scope differences
     */
    private function createScopeDifferenceGap(
        ComplianceMapping $complianceMapping,
        ComplianceRequirement $source,
        ComplianceRequirement $target
    ): MappingGapItem {
        $mappingGapItem = new MappingGapItem();
        $mappingGapItem->setMapping($complianceMapping);
        $mappingGapItem->setGapType('scope_difference');

        $sourceCategory = $source->getCategory() ?? 'Unbekannt';
        $targetCategory = $target->getCategory() ?? 'Unbekannt';

        $description = sprintf(
            'Scope-Unterschied erkannt: Source-Requirement (Kategorie: %s) und Target-Requirement (Kategorie: %s) ' .
            'haben unterschiedliche Schwerpunkte oder Anwendungsbereiche. ' .
            'Eine Eins-zu-Eins-Übertragung ist nicht vollständig möglich.',
            $sourceCategory,
            $targetCategory
        );

        $mappingGapItem->setDescription($description);
        $mappingGapItem->setPriority('medium');
        $mappingGapItem->setPercentageImpact(15);
        $mappingGapItem->setConfidence($this->calculateGapConfidence(0.3, 'scope'));

        $mappingGapItem->setRecommendedAction(
            'Prüfen, ob die unterschiedlichen Scopes durch Kombination mehrerer Source-Requirements abgedeckt werden können, ' .
            'oder ob zusätzliche spezifische Kontrollen für den Target-Scope erforderlich sind.'
        );

        $mappingGapItem->setEstimatedEffort(4);
        $mappingGapItem->setIdentificationSource('algorithm');
        $mappingGapItem->setStatus('identified');

        return $mappingGapItem;
    }

    /**
     * Identify additional requirements in target
     */
    private function identifyAdditionalRequirements(
        ComplianceMapping $complianceMapping,
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
            $mappingGapItem = new MappingGapItem();
            $mappingGapItem->setMapping($complianceMapping);
            $mappingGapItem->setGapType('additional_requirement');

            $uniqueKeywords = array_diff($targetKeywords, $sourceKeywords);

            $description = sprintf(
                'Das Target-Requirement hat zusätzliche Anforderungen, die über das Source-Requirement hinausgehen. ' .
                'Es wurden %d zusätzliche Konzepte identifiziert, die im Source nicht vorhanden sind.',
                count($uniqueKeywords)
            );

            $mappingGapItem->setDescription($description);
            $mappingGapItem->setMissingKeywords(array_values(array_slice($uniqueKeywords, 0, 20)));
            $mappingGapItem->setPriority('high');
            $mappingGapItem->setPercentageImpact(25);
            $mappingGapItem->setConfidence($this->calculateGapConfidence(count($uniqueKeywords), 'additional'));

            $mappingGapItem->setRecommendedAction(
                'Die zusätzlichen Anforderungen des Target-Requirements müssen separat implementiert werden. ' .
                'Prüfen Sie, ob andere Requirements des Source-Frameworks diese Aspekte abdecken, ' .
                'oder ob neue Kontrollen erforderlich sind.'
            );

            $mappingGapItem->setEstimatedEffort((int) ceil(count($uniqueKeywords) * 0.5));
            $mappingGapItem->setIdentificationSource('algorithm');
            $mappingGapItem->setStatus('identified');

            return $mappingGapItem;
        }

        return null;
    }

    /**
     * Check if there's an evidence gap
     */
    private function hasEvidenceGap(
        ComplianceMapping $complianceMapping
    ): bool {
        // Evidence gap exists if:
        // - Mapping percentage is high (>80)
        // - But textual similarity is medium (0.5-0.7)
        // - Suggesting control exists but documentation is weak

        $mappingPercentage = $complianceMapping->getMappingPercentage();
        $textualSimilarity = $complianceMapping->getTextualSimilarity() ?? 0;

        return $mappingPercentage > 80 && $textualSimilarity > 0.5 && $textualSimilarity < 0.7;
    }

    /**
     * Create evidence gap
     */
    private function createEvidenceGap(ComplianceMapping $complianceMapping): MappingGapItem {
        $mappingGapItem = new MappingGapItem();
        $mappingGapItem->setMapping($complianceMapping);
        $mappingGapItem->setGapType('evidence_gap');

        $description = 'Die Kontrolle scheint grundsätzlich vorhanden zu sein, jedoch fehlt möglicherweise ' .
            'vollständige Dokumentation oder Nachweise (Evidenz) für die Umsetzung. ' .
            'Die Mapping-Percentage ist hoch, aber die textuelle Übereinstimmung deutet auf Lücken hin.';

        $mappingGapItem->setDescription($description);
        $mappingGapItem->setPriority('medium');
        $mappingGapItem->setPercentageImpact(10);
        $mappingGapItem->setConfidence($this->calculateGapConfidence(0.6, 'evidence'));

        $mappingGapItem->setRecommendedAction(
            'Dokumentation vervollständigen: Erstellen Sie ausführliche Beschreibungen der implementierten Kontrollen, ' .
            'sammeln Sie Nachweise (Screenshots, Policies, Protokolle) und dokumentieren Sie die Umsetzung gemäß ' .
            'den Anforderungen des Target-Frameworks.'
        );

        $mappingGapItem->setEstimatedEffort(3);
        $mappingGapItem->setIdentificationSource('algorithm');
        $mappingGapItem->setStatus('identified');

        return $mappingGapItem;
    }

    /**
     * Calculate confidence for a gap identification
     */
    private function calculateGapConfidence(int|float $value, string $type): int
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
    private function generateKeywordRecommendations(array $missingKeywords): string
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
        foreach ($missingKeywords as $missingKeyword) {
            foreach ($categories as $key => $recommendation) {
                if (stripos((string) $missingKeyword, $key) !== false && !in_array($recommendation, $added, true)) {
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
    private function getRequirementText(ComplianceRequirement $complianceRequirement): string
    {
        $parts = [];

        if ($title = $complianceRequirement->getTitle()) {
            $parts[] = $title;
        }

        if ($description = $complianceRequirement->getDescription()) {
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

        foreach ($gapItems as $gapItem) {
            $totalImpact += $gapItem->getPercentageImpact();
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

        foreach ($gapItems as $gapItem) {
            // By type
            $type = $gapItem->getGapType();
            if (!isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = 0;
            }
            $summary['by_type'][$type]++;

            // By priority
            $priority = $gapItem->getPriority();
            if (!isset($summary['by_priority'][$priority])) {
                $summary['by_priority'][$priority] = 0;
            }
            $summary['by_priority'][$priority]++;

            // Total effort
            $summary['total_effort'] += $gapItem->getEstimatedEffort() ?? 0;

            // High confidence gaps
            if ($gapItem->getConfidence() >= self::CONFIDENCE_THRESHOLD_HIGH) {
                $summary['high_confidence_gaps']++;
            }
        }

        return $summary;
    }
}
