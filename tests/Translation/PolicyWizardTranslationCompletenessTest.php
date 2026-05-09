<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Translation completeness checks for the Policy-Wizard module (W7-F).
 *
 * Guards against translation drift across the 13 policy-wizard
 * translation files (one DE + one EN per domain). Each test compares
 * the flattened key set between locales and asserts strict parity.
 *
 * Domains covered:
 *   - policy_wizard           (UI chrome, ~520 keys)
 *   - policy_iso27001         (ISO 27001 batch 1)
 *   - policy_iso27001_batch2  (ISO 27001 batch 2)
 *   - policy_iso27001_batch3  (ISO 27001 batch 3)
 *   - policy_iso27001_batch4  (ISO 27001 batch 4)
 *   - policy_bsi_batch1       (BSI Grundschutz batch 1)
 *   - policy_bsi_batch2       (BSI Grundschutz batch 2)
 *   - policy_bsi_batch3       (BSI Grundschutz batch 3)
 *   - policy_bcm_batch1       (BCM ISO 22301 + BSI 200-4 batch 1)
 *   - policy_bcm_batch2       (BCM ISO 22301 + BSI 200-4 batch 2)
 *   - policy_dora             (DORA addon)
 *   - policy_privacy_batch1   (Privacy / GDPR / DPO standalone templates)
 *   - policy_privacy_sections (Privacy GDPR-section embeds)
 */
final class PolicyWizardTranslationCompletenessTest extends TestCase
{
    private const TRANSLATIONS_DIR = __DIR__ . '/../../translations';

    /** @return list<string> */
    private const POLICY_WIZARD_DOMAINS = [
        'policy_wizard',
        'policy_iso27001',
        'policy_iso27001_batch2',
        'policy_iso27001_batch3',
        'policy_iso27001_batch4',
        'policy_bsi_batch1',
        'policy_bsi_batch2',
        'policy_bsi_batch3',
        'policy_bcm_batch1',
        'policy_bcm_batch2',
        'policy_dora',
        'policy_privacy_batch1',
        'policy_privacy_sections',
    ];

    /** @return list<string> Domains carrying standard-specific content (one batch per standard). */
    private const STANDARD_SPECIFIC_DOMAINS = [
        'policy_iso27001',
        'policy_iso27001_batch2',
        'policy_iso27001_batch3',
        'policy_iso27001_batch4',
        'policy_bsi_batch1',
        'policy_bsi_batch2',
        'policy_bsi_batch3',
        'policy_bcm_batch1',
        'policy_bcm_batch2',
        'policy_dora',
        'policy_privacy_batch1',
        'policy_privacy_sections',
    ];

    /**
     * Test 1: every key in DE has a counterpart in EN and vice versa.
     */
    #[Test]
    public function testAllPolicyWizardKeysHaveDeAndEn(): void
    {
        $missingByDomain = [];

        foreach (self::POLICY_WIZARD_DOMAINS as $domain) {
            $deKeys = $this->loadFlattenedKeys($domain, 'de');
            $enKeys = $this->loadFlattenedKeys($domain, 'en');

            $missingInEn = array_diff($deKeys, $enKeys);
            $missingInDe = array_diff($enKeys, $deKeys);

            if ($missingInEn !== [] || $missingInDe !== []) {
                $missingByDomain[$domain] = [
                    'missing_in_en' => array_values($missingInEn),
                    'missing_in_de' => array_values($missingInDe),
                ];
            }
        }

        self::assertSame(
            [],
            $missingByDomain,
            "Translation key parity violated. Missing keys per domain:\n"
            . json_encode($missingByDomain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Test 2: each standard-specific domain ships both locales as
     * non-empty files. Guards against ship-DE-forget-EN regressions when
     * adding new standards content batches.
     *
     * @param string $domain
     */
    #[Test]
    #[DataProvider('standardSpecificDomainsProvider')]
    public function testAllStandardSpecificContentHasBothLocales(string $domain): void
    {
        foreach (['de', 'en'] as $locale) {
            $path = sprintf('%s/%s.%s.yaml', self::TRANSLATIONS_DIR, $domain, $locale);

            self::assertFileExists(
                $path,
                "Missing translation file for standard-specific domain '{$domain}' locale '{$locale}'.",
            );

            $content = file_get_contents($path);
            self::assertNotFalse($content, "Could not read {$path}.");
            self::assertNotSame(
                '',
                trim($content),
                "Translation file '{$path}' is empty.",
            );

            $parsed = Yaml::parse($content);
            self::assertIsArray(
                $parsed,
                "Translation file '{$path}' did not parse as a YAML mapping.",
            );
            self::assertNotEmpty(
                $parsed,
                "Translation file '{$path}' parsed to an empty array.",
            );
        }
    }

    /**
     * Test 3: no unplaceholdered translation keys in EN. Catches "TBD",
     * "XXX", "TODO" markers that slip through translation review.
     *
     * Allow-list: legitimate UI strings that contain the literal word
     * "Placeholder" / "TODO" as content (e.g., validation error
     * messages, example tooltips).
     */
    #[Test]
    public function testNoUnplaceholderedTranslationKeys(): void
    {
        $allowedSubstringPatterns = [
            // Validation error message that literally describes
            // forbidden placeholders to the user.
            '/policy_wizard\.error\.tailoring_quality\.placeholder/',
            // Example tooltip values that legitimately use the
            // "Example: ..." pattern in English.
            '/\.placeholder$/',
        ];

        $offenders = [];

        foreach (self::POLICY_WIZARD_DOMAINS as $domain) {
            $en = $this->loadFlattenedTree($domain, 'en');

            foreach ($en as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }

                if ($this->matchesAny($key, $allowedSubstringPatterns)) {
                    continue;
                }

                $upper = strtoupper($value);
                $hasPlaceholder = str_contains($upper, 'XXX')
                    || str_contains($upper, 'TBD')
                    || str_contains($upper, 'TODO')
                    // Translation marked with a single trailing "?" used
                    // as a fill-me-in indicator (filter out legitimate
                    // questions like "Are you sure?" by requiring the
                    // value to be very short and end with "?").
                    || (str_ends_with(trim($value), '?') && strlen(trim($value)) < 8);

                if ($hasPlaceholder) {
                    $offenders[] = [
                        'domain' => $domain,
                        'key' => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Found unplaceholdered translation values in EN:\n"
            . json_encode($offenders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return list<array{0:string}> */
    public static function standardSpecificDomainsProvider(): array
    {
        return array_map(static fn (string $d): array => [$d], self::STANDARD_SPECIFIC_DOMAINS);
    }

    // --- helpers ---

    /** @return list<string> */
    private function loadFlattenedKeys(string $domain, string $locale): array
    {
        return array_keys($this->loadFlattenedTree($domain, $locale));
    }

    /** @return array<string, mixed> */
    private function loadFlattenedTree(string $domain, string $locale): array
    {
        $path = sprintf('%s/%s.%s.yaml', self::TRANSLATIONS_DIR, $domain, $locale);

        if (!is_file($path)) {
            self::fail("Translation file not found: {$path}");
        }

        $parsed = Yaml::parseFile($path);

        if (!is_array($parsed)) {
            self::fail("YAML root for {$path} is not an array.");
        }

        return $this->flatten($parsed);
    }

    /**
     * Flatten a nested associative array into dot-notation key => leaf value.
     *
     * @param array<int|string, mixed> $tree
     * @return array<string, mixed>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $this->isAssoc($value)) {
                $out += $this->flatten($value, $full);
                continue;
            }

            $out[$full] = $value;
        }

        return $out;
    }

    /** @param array<int|string, mixed> $arr */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }
        return false;
    }
}
