<?php

declare(strict_types=1);

namespace App\Service\Certificate;

/**
 * Heuristic OCR-text → draft certificate fields extractor.
 *
 * Pure function: string in → array out. No I/O, no side effects, fully unit-testable.
 *
 * Used by the async import job (T11) that feeds OCR output into a draft for
 * user confirmation (T12). Multi-lingual: German + English keywords.
 *
 * Framework code mapping
 * ─────────────────────
 * The frameworkGuess codes match the style used in CertificateCoverageRule
 * examples ('ISO27001' without punctuation) and the app's compliance framework
 * slugs where determinable:
 *
 *   Detected norm string              → frameworkGuess code
 *   ─────────────────────────────────────────────────────────
 *   ISO/IEC 27001 / ISO 27001         → ISO27001
 *   ISO/IEC 27017                     → ISO27017
 *   ISO/IEC 27018                     → ISO27018
 *   ISO/IEC 27701                     → ISO27701
 *   ISO 9001                          → ISO9001
 *   ISO 14001                         → ISO14001
 *   ISO 22301                         → ISO22301
 *   ISO 20000 / ISO/IEC 20000         → ISO20000
 *   ISO 45001                         → ISO45001
 *   BSI C5                            → BSI_C5
 *   TISAX                             → TISAX
 *   SOC 2 / SOC2                      → SOC2
 *   EN 50600                          → EN50600
 *   PCI DSS / PCI-DSS                 → PCI_DSS
 *   NIST CSF / NIST 800               → NIST_CSF
 *
 * LLM extraction deferred (Phase 3): a future strategy could send $text to an
 * LLM for higher-accuracy field extraction. Intentionally not implemented.
 * Seam: swap or extend the extract() body with an LLM-backed strategy that
 * returns the same array shape, keeping this method as the public API.
 */
final class CertificateFieldExtractor
{
    // -------------------------------------------------------------------------
    // Known certification body name patterns → canonical display name.
    // Order matters: more specific entries must appear before broader ones
    // (e.g. "TÜV Rheinland" before "TÜV").
    // -------------------------------------------------------------------------

    /** @var array<string, string> regex-fragment => canonical name */
    private const CERT_BODIES = [
        'T[ÜU]V\s+S[ÜU]D'         => 'TÜV SÜD',
        'T[ÜU]V\s+Rheinland'       => 'TÜV Rheinland',
        'T[ÜU]V\s+NORD'            => 'TÜV NORD',
        'T[ÜU]V'                   => 'TÜV',          // generic TÜV fallback
        'DEKRA'                    => 'DEKRA',
        'DNV'                      => 'DNV',
        'BSI\s+Group'              => 'BSI',
        'Bureau\s+Veritas'         => 'Bureau Veritas',
        'SGS'                      => 'SGS',
        'DQS'                      => 'DQS',
        'Intertek'                 => 'Intertek',
        'Lloyd.s\s+Register'       => "Lloyd's Register",
        'LRQA'                     => 'LRQA',
        'UL\s+Solutions'           => 'UL Solutions',
        'A-SIT'                    => 'A-SIT',
    ];

    // -------------------------------------------------------------------------
    // Framework norm string patterns → app framework code.
    // Match longest/most-specific first to avoid ISO27001 shadowing ISO27017.
    // -------------------------------------------------------------------------

    /** @var array<string, string> regex-fragment => framework code */
    private const FRAMEWORK_PATTERNS = [
        // ISO 27k sub-standards (order: specific before broad)
        // Note: forward slash escaped as \/ to avoid conflicting with the / regex delimiter
        'ISO\s*\/?\s*IEC\s+27701'          => 'ISO27701',
        'ISO\s*\/?\s*IEC\s+27018'          => 'ISO27018',
        'ISO\s*\/?\s*IEC\s+27017'          => 'ISO27017',
        'ISO\s*\/?\s*IEC\s+27001'          => 'ISO27001',
        'ISO\s+27001'                       => 'ISO27001',
        // ISO 20000
        'ISO\s*\/?\s*IEC\s+20000'          => 'ISO20000',
        'ISO\s+20000'                       => 'ISO20000',
        // ISO 22301
        'ISO\s+22301'                       => 'ISO22301',
        // ISO 9001
        'ISO\s+9001'                        => 'ISO9001',
        // ISO 14001
        'ISO\s+14001'                       => 'ISO14001',
        // ISO 45001
        'ISO\s+45001'                       => 'ISO45001',
        // BSI C5
        'BSI\s+C5'                          => 'BSI_C5',
        // TISAX (ENX label, VDA ISA)
        'TISAX'                             => 'TISAX',
        // SOC 2
        'SOC\s*2'                           => 'SOC2',
        // EN 50600
        'EN\s+50600'                        => 'EN50600',
        // PCI DSS
        'PCI[\s\-]DSS'                      => 'PCI_DSS',
        // NIST
        'NIST\s+CSF'                        => 'NIST_CSF',
        'NIST\s+800'                        => 'NIST_CSF',
    ];

    // -------------------------------------------------------------------------
    // English month names → month number
    // -------------------------------------------------------------------------

    /** @var array<string, string> */
    private const EN_MONTHS = [
        'january' => '01', 'february' => '02', 'march'     => '03',
        'april'   => '04', 'may'      => '05', 'june'      => '06',
        'july'    => '07', 'august'   => '08', 'september' => '09',
        'october' => '10', 'november' => '11', 'december'  => '12',
    ];

    // -------------------------------------------------------------------------
    // German month names → month number
    // -------------------------------------------------------------------------

    /** @var array<string, string> */
    private const DE_MONTHS = [
        'januar'    => '01', 'februar'   => '02', 'märz'      => '03',
        'april'     => '04', 'mai'       => '05', 'juni'      => '06',
        'juli'      => '07', 'august'    => '08', 'september' => '09',
        'oktober'   => '10', 'november'  => '11', 'dezember'  => '12',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Extract certificate draft fields from raw OCR text.
     *
     * @param string $text Raw OCR output (may contain noise, extra whitespace, mixed language)
     *
     * @return array{
     *     certBody: string|null,
     *     certNumber: string|null,
     *     validUntil: string|null,
     *     issueDate: string|null,
     *     holder: string|null,
     *     frameworkGuess: string|null,
     *     confidence: float,
     * }
     */
    public function extract(string $text): array
    {
        // LLM extraction deferred (Phase 3): a future strategy could send $text to an
        // LLM for higher-accuracy field extraction. Intentionally not implemented.
        // Seam: replace the heuristic calls below with an LLM strategy object that
        // returns the same $draft array shape.

        $draft = [
            'certBody'       => $this->extractCertBody($text),
            'certNumber'     => $this->extractCertNumber($text),
            'validUntil'     => $this->extractValidUntil($text),
            'issueDate'      => $this->extractIssueDate($text),
            'holder'         => $this->extractHolder($text),
            'frameworkGuess' => $this->extractFrameworkGuess($text),
        ];

        $draft['confidence'] = $this->computeConfidence($draft);

        return $draft;
    }

    // -------------------------------------------------------------------------
    // Field extractors
    // -------------------------------------------------------------------------

    private function extractCertBody(string $text): ?string
    {
        foreach (self::CERT_BODIES as $pattern => $name) {
            if (preg_match('/' . $pattern . '/iu', $text)) {
                return $name;
            }
        }

        return null;
    }

    private function extractCertNumber(string $text): ?string
    {
        // Patterns (DE + EN, case-insensitive):
        //   "Certificate No.:  12 345 67890"
        //   "Zertifikat-Nr. ABC-2024-001"
        //   "Registration No 12345-XYZ"
        //   "Reg.-Nr. DE-2024-99887"
        // [^\S\n] = horizontal whitespace only — prevents the capture from bleeding across line breaks
        $pattern = '/(?:certificate\s+no\.?:?|zertifikat[-\s]nr\.?|registration\s+no\.?:?|reg\.?-?nr\.?)[^\S\n]*([A-Z0-9][\w\h\-\/\.]{1,40})/iu';

        if (preg_match($pattern, $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Extract the validity end date and normalise to YYYY-MM-DD.
     * Keywords: valid until / gültig bis / expiry date / expiration date / expires
     */
    private function extractValidUntil(string $text): ?string
    {
        $keywords = '(?:valid\s+until|g[üu]ltig\s+bis|expiry\s+date|expiration\s+date|expires)';
        $datePattern = $this->datePattern();

        $pattern = '/' . $keywords . '\s*:?\s*(' . $datePattern . ')/iu';

        if (preg_match($pattern, $text, $m)) {
            return $this->normalizeDate($m[1]);
        }

        return null;
    }

    /**
     * Extract the issue / valid-from date and normalise to YYYY-MM-DD.
     * Keywords: date of issue / Ausstellungsdatum / valid from / gültig ab / issued on
     */
    private function extractIssueDate(string $text): ?string
    {
        $keywords = '(?:date\s+of\s+issue|ausstellungsdatum|valid\s+from|g[üu]ltig\s+ab|issued\s+on)';
        $datePattern = $this->datePattern();

        $pattern = '/' . $keywords . '\s*:?\s*(' . $datePattern . ')/iu';

        if (preg_match($pattern, $text, $m)) {
            return $this->normalizeDate($m[1]);
        }

        return null;
    }

    /**
     * Extract the certificate holder organisation.
     * Keywords: issued to / ausgestellt für / Zertifikatsinhaber / certifies that (next line)
     */
    private function extractHolder(string $text): ?string
    {
        $keywords = '(?:issued\s+to|ausgestellt\s+f[üu]r|zertifikatsinhaber)';

        // "issued to Acme GmbH" — capture until end of line or comma
        $pattern = '/' . $keywords . '\s*:?\s*([^\n,;]{2,80})/iu';

        if (preg_match($pattern, $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractFrameworkGuess(string $text): ?string
    {
        foreach (self::FRAMEWORK_PATTERNS as $pattern => $code) {
            if (preg_match('/' . $pattern . '/iu', $text)) {
                return $code;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Confidence
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $draft
     */
    private function computeConfidence(array $draft): float
    {
        // The 6 extractable fields (confidence excludes the confidence key itself)
        $extractableKeys = ['certBody', 'certNumber', 'validUntil', 'issueDate', 'holder', 'frameworkGuess'];
        $nonNull = 0;

        foreach ($extractableKeys as $key) {
            if ($draft[$key] !== null) {
                ++$nonNull;
            }
        }

        return $nonNull / count($extractableKeys);
    }

    // -------------------------------------------------------------------------
    // Date helpers
    // -------------------------------------------------------------------------

    /**
     * Regex fragment matching any supported date format (non-capturing sub-groups).
     *
     * Supported formats:
     *   YYYY-MM-DD
     *   DD.MM.YYYY
     *   DD/MM/YYYY
     *   D Month YYYY  (English: "14 March 2027")
     *   D. Monat YYYY (German: "14. März 2024")
     */
    private function datePattern(): string
    {
        $enMonths = implode('|', array_keys(self::EN_MONTHS));
        $deMonths = implode('|', array_keys(self::DE_MONTHS));
        $allMonths = $enMonths . '|' . $deMonths;

        return '(?:'
            . '\d{4}-\d{2}-\d{2}'                        // YYYY-MM-DD
            . '|\d{1,2}\.\d{2}\.\d{4}'                   // DD.MM.YYYY
            . '|\d{1,2}\/\d{2}\/\d{4}'                   // DD/MM/YYYY
            . '|\d{1,2}\.?\s+(?:' . $allMonths . ')\s+\d{4}'  // D[.] Month/Monat YYYY
            . ')';
    }

    /**
     * Normalise any matched date string to YYYY-MM-DD.
     * Returns null if the string cannot be parsed (should not happen given the
     * pattern already constrains input, but defended for safety).
     */
    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);

        // YYYY-MM-DD — already canonical
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // DD.MM.YYYY
        if (preg_match('/^(\d{1,2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // DD/MM/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // D[.] Month/Monat YYYY  (long-form, EN + DE)
        $enMonths = implode('|', array_keys(self::EN_MONTHS));
        $deMonths = implode('|', array_keys(self::DE_MONTHS));
        $allMonths = $enMonths . '|' . $deMonths;

        if (preg_match('/^(\d{1,2})\.?\s+(' . $allMonths . ')\s+(\d{4})$/iu', $raw, $m)) {
            $monthName = mb_strtolower($m[2]);
            $monthNum  = self::EN_MONTHS[$monthName] ?? self::DE_MONTHS[$monthName] ?? null;

            if ($monthNum !== null) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $monthNum, (int) $m[1]);
            }
        }

        return null;
    }
}
