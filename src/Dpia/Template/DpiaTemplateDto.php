<?php

declare(strict_types=1);

namespace App\Dpia\Template;

/**
 * F31 — Curated sectoral DPIA template value-object.
 *
 * Immutable once constructed. Fields map 1:1 onto
 * DataProtectionImpactAssessment setters used by SectoralDpiaPreFiller.
 */
final readonly class DpiaTemplateDto
{
    /**
     * @param list<string> $dataCategories           Art. 9 / Art. 4 category keys
     * @param list<string> $dataSubjectCategories    Data-subject group keys
     * @param list<array{title: string, description: string, likelihood: string, impact: string, severity: string}> $identifiedRisks
     */
    public function __construct(
        /** Machine-readable library key (slug) */
        public readonly string $key,
        /** Translation key for the template name, resolved from privacy domain */
        public readonly string $nameTransKey,
        /** Translation key for the one-line "for whom / when" usage hint */
        public readonly string $usageHint,
        /** Icon name for the Aurora card picker (lucide icon slug) */
        public readonly string $icon,
        public readonly string $processingDescription,
        public readonly string $processingPurposes,
        public readonly array  $dataCategories,
        public readonly array  $dataSubjectCategories,
        public readonly string $necessityAssessment,
        public readonly string $proportionalityAssessment,
        /**
         * Art. 6 / Art. 9 legal basis key matching DataProtectionImpactAssessment::legalBasis choices.
         * Examples: 'legitimate_interests', 'legal_obligation', 'vital_interests', 'art9_bdsg22'.
         */
        public readonly string $legalBasis,
        public readonly string $legislativeCompliance,
        /** @var list<array{title: string, description: string, likelihood: string, impact: string, severity: string}> */
        public readonly array  $identifiedRisks,
        public readonly string $riskLevel,
        public readonly string $likelihood,
        public readonly string $impact,
        public readonly string $dataSubjectRisks,
        public readonly string $technicalMeasures,
        public readonly string $organizationalMeasures,
        public readonly string $residualRiskAssessment,
        public readonly string $residualRiskLevel,
        /** Optional: whether Art. 36 prior supervisory consultation is recommended */
        public readonly bool   $requiresSupervisoryConsultation = false,
    ) {}
}
