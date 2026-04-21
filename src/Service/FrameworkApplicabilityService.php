<?php

declare(strict_types=1);

namespace App\Service;

/**
 * FrameworkApplicabilityService — Sprint 9 Wizard-Integration.
 *
 * Klassifiziert für einen gegebenen Tenant-Kontext (Branche, Größe,
 * Land, optional AI-High-Risk-Flag) jedes der 23 Frameworks in einen
 * von drei Buckets:
 *
 *   - mandatory   → "muss geladen werden" (rechtliche/vertragliche Pflicht)
 *   - recommended → "sollte geladen werden" (branchenüblich / Markt-
 *                    Zugangsvoraussetzung / best practice)
 *   - optional    → "kann geladen werden" (freiwillig / nur bei
 *                    spezifischem Bedarf)
 *
 * Jede Zuordnung hat einen Grund-Text (Übersetzungsschlüssel) für
 * die Wizard-UI — damit der Junior-Implementer versteht, WARUM das
 * Tool etwas empfiehlt.
 *
 * Komplement zur `getAvailableFrameworks()`-Liste im
 * `ComplianceFrameworkLoaderService`: diese Klasse macht die dort
 * abstrakte `applicability`-Info konkret für den Tenant-Kontext.
 */
final class FrameworkApplicabilityService
{
    public const BUCKET_MANDATORY = 'mandatory';
    public const BUCKET_RECOMMENDED = 'recommended';
    public const BUCKET_OPTIONAL = 'optional';

    /** @var list<string> */
    private const NIS2_SECTORS = [
        'energy', 'financial_services', 'healthcare', 'transportation',
        'telecommunications', 'critical_infrastructure', 'digital_health',
        'cloud_services', 'public_sector', 'manufacturing', 'it_services',
    ];

    /** @var list<string> */
    private const KRITIS_SECTORS = [
        'energy', 'financial_services', 'healthcare', 'transportation',
        'telecommunications', 'critical_infrastructure',
    ];

    /** @var list<string> */
    private const EU_COUNTRIES = [
        'DE', 'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'EU_OTHER',
    ];

    /** @var list<string> */
    private const LARGE_SIZES = ['51-250', '251-1000', '1001+'];

    /**
     * @param list<string> $industries  Eine oder mehrere Branchen-Codes
     * @param string       $employeeCount  Range-String: '1-10', '11-50', '51-250', '251-1000', '1001+'
     * @param string       $country        ISO-2-Country-Code
     * @param bool         $aiHighRisk     True wenn Tenant High-Risk-AI-Systeme betreibt
     *
     * @return array<string, array{bucket: string, reason_key: string}>
     *   Keys = Framework-Codes, Werte = Bucket + Grund-Translation-Key
     */
    public function classifyAll(
        array $industries,
        string $employeeCount,
        string $country,
        bool $aiHighRisk = false,
    ): array {
        $isEU = in_array($country, self::EU_COUNTRIES, true);
        $isDE = $country === 'DE';
        $isLarge = in_array($employeeCount, self::LARGE_SIZES, true);
        $isNis2Sector = (bool) array_intersect($industries, self::NIS2_SECTORS);
        $isKritisSector = (bool) array_intersect($industries, self::KRITIS_SECTORS);

        $has = fn(string $code): bool => in_array($code, $industries, true);

        $result = [];

        // ISO 27001 — Kern-Ziel des Tools, immer Pflicht
        $result['ISO27001'] = [
            'bucket' => self::BUCKET_MANDATORY,
            'reason_key' => 'setup.applicability.reason.iso27001',
        ];

        // GDPR / BDSG — PII-Pflichten
        if ($isEU) {
            $result['GDPR'] = [
                'bucket' => self::BUCKET_MANDATORY,
                'reason_key' => 'setup.applicability.reason.gdpr_eu',
            ];
        } else {
            $result['GDPR'] = [
                'bucket' => self::BUCKET_RECOMMENDED,
                'reason_key' => 'setup.applicability.reason.gdpr_non_eu',
            ];
        }
        $result['BDSG'] = $isDE
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.bdsg_de']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.bdsg_non_de'];

        // NIS2 + NIS2UMSUCG
        if ($isEU && $isNis2Sector && $isLarge) {
            $result['NIS2'] = [
                'bucket' => self::BUCKET_MANDATORY,
                'reason_key' => 'setup.applicability.reason.nis2_mandatory',
            ];
            $result['NIS2UMSUCG'] = $isDE
                ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.nis2umsucg_mandatory']
                : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.nis2umsucg_non_de'];
        } elseif ($isEU && $isNis2Sector) {
            $result['NIS2'] = [
                'bucket' => self::BUCKET_RECOMMENDED,
                'reason_key' => 'setup.applicability.reason.nis2_below_threshold',
            ];
            $result['NIS2UMSUCG'] = $isDE
                ? ['bucket' => self::BUCKET_RECOMMENDED, 'reason_key' => 'setup.applicability.reason.nis2umsucg_below_threshold']
                : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.nis2umsucg_non_de'];
        } else {
            $result['NIS2'] = [
                'bucket' => self::BUCKET_OPTIONAL,
                'reason_key' => 'setup.applicability.reason.nis2_not_applicable',
            ];
            $result['NIS2UMSUCG'] = [
                'bucket' => self::BUCKET_OPTIONAL,
                'reason_key' => 'setup.applicability.reason.nis2umsucg_non_de',
            ];
        }

        // DORA — Finanzdienstleister
        $result['DORA'] = $has('financial_services')
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.dora_financial']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.dora_not_financial'];

        // TISAX — Automotive (Marktzugang)
        $result['TISAX'] = $has('automotive')
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.tisax_automotive']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.tisax_not_automotive'];

        // GXP — Pharma
        $result['GXP'] = $has('pharmaceutical')
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.gxp_pharma']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.gxp_not_pharma'];

        // DIGAV — DiGA-Hersteller (DE)
        $result['DIGAV'] = ($has('digital_health') && $isDE)
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.digav_de_health']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.digav_other'];

        // TKG — Telekom-Provider (DE)
        $result['TKG-2024'] = ($has('telecommunications') && $isDE)
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.tkg_de_telco']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.tkg_other'];

        // KRITIS + KRITIS-HEALTH
        if ($isDE && $isKritisSector && $isLarge) {
            $result['KRITIS'] = [
                'bucket' => self::BUCKET_MANDATORY,
                'reason_key' => 'setup.applicability.reason.kritis_de_sector_large',
            ];
        } elseif ($isDE && $isKritisSector) {
            $result['KRITIS'] = [
                'bucket' => self::BUCKET_RECOMMENDED,
                'reason_key' => 'setup.applicability.reason.kritis_de_sector_small',
            ];
        } else {
            $result['KRITIS'] = [
                'bucket' => self::BUCKET_OPTIONAL,
                'reason_key' => 'setup.applicability.reason.kritis_other',
            ];
        }
        $result['KRITIS-HEALTH'] = ($isDE && $has('healthcare'))
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.kritis_health_de']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.kritis_health_other'];

        // BSI IT-Grundschutz — Public Sector / Behörden
        if ($isDE && $has('public_sector')) {
            $result['BSI_GRUNDSCHUTZ'] = [
                'bucket' => self::BUCKET_MANDATORY,
                'reason_key' => 'setup.applicability.reason.bsi_grundschutz_de_public',
            ];
        } elseif ($isDE) {
            $result['BSI_GRUNDSCHUTZ'] = [
                'bucket' => self::BUCKET_RECOMMENDED,
                'reason_key' => 'setup.applicability.reason.bsi_grundschutz_de_private',
            ];
        } else {
            $result['BSI_GRUNDSCHUTZ'] = [
                'bucket' => self::BUCKET_OPTIONAL,
                'reason_key' => 'setup.applicability.reason.bsi_grundschutz_other',
            ];
        }

        // EU AI Act — nur wenn High-Risk-AI
        $result['EU-AI-ACT'] = $aiHighRisk
            ? ['bucket' => self::BUCKET_MANDATORY, 'reason_key' => 'setup.applicability.reason.eu_ai_act_high_risk']
            : ['bucket' => self::BUCKET_OPTIONAL, 'reason_key' => 'setup.applicability.reason.eu_ai_act_not_high_risk'];

        // Voluntary / Markt-Standards
        $result['ISO27701'] = [
            'bucket' => $isEU ? self::BUCKET_RECOMMENDED : self::BUCKET_OPTIONAL,
            'reason_key' => $isEU
                ? 'setup.applicability.reason.iso27701_eu_privacy'
                : 'setup.applicability.reason.iso27701_non_eu',
        ];
        $result['ISO27701_2025'] = [
            'bucket' => self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.iso27701_2025_newer',
        ];
        $result['ISO-22301'] = [
            'bucket' => $isKritisSector || $has('financial_services') ? self::BUCKET_RECOMMENDED : self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.iso22301_bcm',
        ];
        $result['ISO27005'] = [
            'bucket' => self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.iso27005_risk',
        ];
        $result['BSI-C5'] = [
            'bucket' => $has('cloud_services') && $isDE ? self::BUCKET_RECOMMENDED : self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.bsi_c5',
        ];
        $result['BSI-C5-2026'] = [
            'bucket' => $has('cloud_services') && $isDE ? self::BUCKET_RECOMMENDED : self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.bsi_c5_2026',
        ];
        $result['SOC2'] = [
            'bucket' => $has('it_services') || $has('cloud_services') ? self::BUCKET_RECOMMENDED : self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.soc2',
        ];
        $result['NIST-CSF'] = [
            'bucket' => self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.nist_csf',
        ];
        $result['CIS-CONTROLS'] = [
            'bucket' => self::BUCKET_OPTIONAL,
            'reason_key' => 'setup.applicability.reason.cis_controls',
        ];

        return $result;
    }

    /**
     * Filtert die full classification auf nur einen Bucket.
     *
     * @param array<string, array{bucket: string, reason_key: string}> $classification
     * @return list<string>  Framework-Codes im gewünschten Bucket
     */
    public function codesForBucket(array $classification, string $bucket): array
    {
        $out = [];
        foreach ($classification as $code => $info) {
            if ($info['bucket'] === $bucket) {
                $out[] = $code;
            }
        }
        return $out;
    }
}
