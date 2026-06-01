<?php

declare(strict_types=1);

namespace App\Service\Tisax\Dto;

/**
 * Immutable DTO representing one parsed VDA-ISA control row.
 *
 * All fields except `controlId` and `title` are nullable because
 * workbook layout varies between VDA-ISA versions.
 */
final readonly class VdaIsaControlRow
{
    public function __construct(
        /** e.g. "1.1.1" */
        public string $controlId,

        /** Primary question text (DE preferred, EN fallback) */
        public string $title,

        /** English version of the question when both languages are present */
        public ?string $titleEn,

        /** Objective / background description */
        public ?string $description,

        /** Must-level maturity requirement text */
        public ?string $mustLevel,

        /** Should-level maturity requirement text */
        public ?string $shouldLevel,

        /** High protection requirement text */
        public ?string $highLevel,

        /** Very-high protection requirement text */
        public ?string $veryHighLevel,

        /** ISO 27001 reference (e.g. "A.5.1, A.5.2") */
        public ?string $iso27001Ref,

        /** Auditor evidence hints */
        public ?string $auditEvidenceHint,

        /** Original row index for error reporting */
        public int $rawRowIndex,

        /**
         * Pre-filled Reifegrad score from the uploaded workbook (0-5).
         * Null when the source workbook is unrated. Mapper applies this to
         * new requirements only — never overwrites an existing assessment.
         */
        public ?int $maturityCurrent = null,

        /**
         * Assessment dimension, set from the SOURCE SHEET the row came from —
         * 'information_security' | 'prototype_protection' | 'data_protection'.
         * Authoritative over {@see getTier()} (which only guesses from the ID
         * prefix), because Prototype/Data-Protection sheets restart numbering.
         */
        public string $dimension = 'information_security',

        /**
         * "Implementation description" (EN col E) / "Beschreibung der Umsetzung"
         * (DE col E) — the assessor's documented MEASURE for this control.
         * Previously dropped on the floor; this is the user's "Maßnahme".
         */
        public ?string $implementationDescription = null,

        /**
         * "Reference documentation" (EN col F) / "Verweis auf Dokumentation"
         * (DE col F) — document references backing the implementation.
         * Previously dropped; this is the user's "Dokumente".
         */
        public ?string $referenceDocumentation = null,

        /**
         * Raw maturity/assessment cell (col D) verbatim. IS/PP carry a 0-5
         * Reifegrad (mirrored into {@see $maturityCurrent}); Data Protection
         * uses a tristate label ("OK"/"NOK"/…) that does NOT fit the 0-5 scale,
         * so the verbatim value is preserved here for the DP path.
         */
        public ?string $maturityRaw = null,

        /**
         * Simplified Group Assessment (SGA) additional requirement text — the
         * VDA-ISA 6 "Zusätzliche Anforderungen für das vereinfachte Gruppen
         * Assessment" column (Information Security only). Distinct from the
         * protection-need tiers; applies to the simplified group-audit scope.
         */
        public ?string $sgaLevel = null,
    ) {}

    /**
     * Derive a numeric domain prefix from the control ID (e.g. "1.1.1" → "1").
     */
    public function getDomainPrefix(): string
    {
        $parts = explode('.', $this->controlId);
        return $parts[0] ?? '';
    }

    /**
     * Map domain prefix to the three TISAX assessment tiers.
     *
     * VDA-ISA 6.x official chapter structure:
     *   Information Security: domains 1-6
     *   Prototype Protection: domains 7-9
     *   Data Protection:      domains 10-12
     */
    public function getTier(): string
    {
        $domain = (int) $this->getDomainPrefix();

        if ($domain >= 10 && $domain <= 12) {
            return 'data_protection';
        }
        if ($domain >= 7 && $domain <= 9) {
            return 'prototype_protection';
        }
        // domains 1-6 (and any unrecognised value) → information_security
        return 'information_security';
    }
}
