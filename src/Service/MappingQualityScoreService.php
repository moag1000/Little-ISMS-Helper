<?php

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;

/**
 * Berechnet den Mapping-Quality-Score (MQS, 0-100) für ein
 * ComplianceMapping aus 6 gewichteten Dimensionen:
 *
 *   Provenance              25 %  — Wer behauptet das Mapping? (offiziell ≫ Community ≫ proprietär)
 *   Methodology             20 %  — Wie wurde es erstellt? (Volltext + Review ≫ Tag-Match ≫ "klingt ähnlich")
 *   Confidence              15 %  — Per-Pair-Sicherheit (high/medium/low)
 *   Coverage                15 %  — Wie viele Source-Items haben ≥1 Mapping?
 *   Bidirectional Coherence 15 %  — Stimmt Rückrichtung?
 *   Lifecycle State         10 %  — published > approved > review > draft; deprecated → 0
 *
 * Der Score landet als JSON-Aufschlüsselung in $mapping->mqsBreakdown
 * und als Integer in $mapping->qualityScore.
 */
class MappingQualityScoreService
{
    private const WEIGHT_PROVENANCE = 25;
    private const WEIGHT_METHODOLOGY = 20;
    private const WEIGHT_CONFIDENCE = 15;
    private const WEIGHT_COVERAGE = 15;
    private const WEIGHT_BIDIRECTIONAL = 15;
    private const WEIGHT_LIFECYCLE = 10;

    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * Berechnet den MQS und persistiert Breakdown + qualityScore am Mapping.
     *
     * @return array{mqs: int, breakdown: array<string, int>}
     */
    public function compute(ComplianceMapping $mapping): array
    {
        $provenance = $this->scoreProvenance($mapping);
        $methodology = $this->scoreMethodology($mapping);
        $confidence = $this->scoreConfidence($mapping);
        $coverage = $this->scoreCoverage($mapping);
        $bidirectional = $this->scoreBidirectional($mapping);
        $lifecycle = $this->scoreLifecycle($mapping);

        $total = $provenance + $methodology + $confidence + $coverage + $bidirectional + $lifecycle;

        $breakdown = [
            'provenance' => $provenance,
            'methodology' => $methodology,
            'confidence' => $confidence,
            'coverage' => $coverage,
            'bidirectional' => $bidirectional,
            'lifecycle' => $lifecycle,
            'total' => $total,
        ];

        $mapping->setMqsBreakdown($breakdown);
        $mapping->setQualityScore($total);

        return ['mqs' => $total, 'breakdown' => $breakdown];
    }

    /**
     * Provenance — offizielle Quelle ≫ Community ≫ proprietär ≫ keine.
     * Bewertung anhand provenanceSource + methodologyType.
     */
    private function scoreProvenance(ComplianceMapping $m): int
    {
        $source = strtolower((string) $m->getProvenanceSource());
        if ($source === '') {
            return 0;
        }
        // Offizielle Standards
        if (str_contains($source, 'iso/iec') || str_contains($source, 'iso ') || str_contains($source, 'enisa')
            || str_contains($source, 'bsi') || str_contains($source, 'bafin') || str_contains($source, 'eba')
            || str_contains($source, 'official') || str_contains($source, 'crosswalk')) {
            return self::WEIGHT_PROVENANCE;
        }
        // Methodology-Typ deutet auf publizierte offizielle Quelle
        if ($m->getMethodologyType() === 'published_official_mapping') {
            return self::WEIGHT_PROVENANCE;
        }
        // URL vorhanden → benannte Quelle (Community/Beratung)
        if ($m->getProvenanceUrl() !== null && $m->getProvenanceUrl() !== '') {
            return (int) round(self::WEIGHT_PROVENANCE * 0.7);  // 17/25
        }
        // Quelle benannt aber ohne URL/offizielle Indikation
        return (int) round(self::WEIGHT_PROVENANCE * 0.4);  // 10/25
    }

    /**
     * Methodology — Volltext + Review ≫ Tag-basiert ≫ Maschine ohne Review.
     */
    private function scoreMethodology(ComplianceMapping $m): int
    {
        $type = $m->getMethodologyType();
        $hasDescription = $m->getMethodologyDescription() !== null && trim($m->getMethodologyDescription()) !== '';

        $base = match ($type) {
            'published_official_mapping' => self::WEIGHT_METHODOLOGY,         // 20
            'text_comparison_with_expert_review' => 18,
            'machine_assisted_with_review' => 14,
            'tag_based' => 10,
            'community_consensus' => 8,
            null, '' => 0,
            default => 6,
        };
        // Beschreibung fehlt → halbiert
        if (!$hasDescription && $base > 0) {
            $base = (int) round($base * 0.6);
        }
        return $base;
    }

    /**
     * Confidence — high/medium/low + analysisConfidence (falls vorhanden).
     */
    private function scoreConfidence(ComplianceMapping $m): int
    {
        $base = match ($m->getConfidence()) {
            'high' => self::WEIGHT_CONFIDENCE,
            'medium' => 10,
            'low' => 5,
            default => 0,
        };
        // analysisConfidence (0-100) als Modulator (wenn gesetzt)
        $ac = $m->getAnalysisConfidence();
        if ($ac !== null) {
            $base = (int) round(($base + ($ac / 100) * self::WEIGHT_CONFIDENCE) / 2);
        }
        return min(self::WEIGHT_CONFIDENCE, $base);
    }

    /**
     * Coverage — wieviel % der Source-Items im Source-Framework haben
     * mindestens ein Mapping zum Target-Framework? Nutzt Repository-Count.
     */
    private function scoreCoverage(ComplianceMapping $m): int
    {
        $sourceFw = $m->getSourceRequirement()?->getComplianceFramework();
        $targetFw = $m->getTargetRequirement()?->getComplianceFramework();
        if ($sourceFw === null || $targetFw === null) {
            return 0;
        }

        $stats = $this->mappingRepository->coverageBetweenFrameworks($sourceFw, $targetFw);
        if ($stats['source_total'] === 0) {
            return 0;
        }
        $pct = $stats['source_with_mapping'] / $stats['source_total'];
        return (int) round($pct * self::WEIGHT_COVERAGE);
    }

    /**
     * Bidirectional Coherence — gibt es ein Mapping in der Rückrichtung
     * (target_framework → source_framework) das auf dieselben Items zeigt?
     */
    private function scoreBidirectional(ComplianceMapping $m): int
    {
        // Self-flag setzt Maximum
        if ($m->isBidirectional()) {
            return self::WEIGHT_BIDIRECTIONAL;
        }

        $sourceFw = $m->getSourceRequirement()?->getComplianceFramework();
        $targetFw = $m->getTargetRequirement()?->getComplianceFramework();
        if ($sourceFw === null || $targetFw === null) {
            return 0;
        }
        $coherence = $this->mappingRepository->reciprocityCoherence($sourceFw, $targetFw);
        return (int) round($coherence * self::WEIGHT_BIDIRECTIONAL);
    }

    /**
     * Lifecycle — published > approved > review > draft; deprecated → 0.
     */
    private function scoreLifecycle(ComplianceMapping $m): int
    {
        return match ($m->getLifecycleState()) {
            'published' => self::WEIGHT_LIFECYCLE,
            'approved' => 8,
            'review' => 5,
            'draft' => 2,
            'deprecated' => 0,
            default => 0,
        };
    }
}
