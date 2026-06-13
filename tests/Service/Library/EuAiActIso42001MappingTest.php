<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment + structural quality tests for the EU-AI-Act ↔ ISO/IEC 42001:2023
 * mapping YAML files (v2.0, both directions).
 *
 * Guards:
 * - source_framework / target_framework codes match the canonical DB values
 *   ('EU-AI-ACT', 'ISO42001') as verified via LoadEuAiActFullCommand and
 *   LoadIso42001FullCommand (findOneBy(['code' => …])).
 * - Required per-entry fields present and non-empty.
 * - relationship and confidence values are within the allowed enumerations.
 * - No entries have empty source or target IDs.
 * - Coverage thresholds (≥30 entries each direction) that protect against
 *   accidental truncation.
 * - Inverse symmetry: every (source, target) pair in the forward file has a
 *   matching (source=target, target=source) pair in the inverse file.
 *
 * STEP 1 NOTE: An official verbatim-extractable CEN-CENELEC JTC 21 harmonised-
 * standard mapping document between EU AI Act (2024/1689) and ISO/IEC 42001:2023
 * is NOT publicly available as an extractable official-crt source. The JTC 21
 * roadmap indicates intent to publish harmonised standards, but no verbatim
 * authoritative document exists from which an official-crt YAML loader could be
 * built without fabrication. STEP 1 = BLOCKED (panel-only, mapping remains
 * methodology 'text_comparison_with_expert_review').
 */
final class EuAiActIso42001MappingTest extends TestCase
{
    /** Canonical DB codes — must match LoadEuAiActFullCommand + LoadIso42001FullCommand */
    private const DB_CODE_EU_AI_ACT = 'EU-AI-ACT';
    private const DB_CODE_ISO_42001 = 'ISO42001';

    private const FILE_FORWARD = __DIR__ . '/../../../fixtures/library/mappings/eu-ai-act_to_iso42001_v2.0.yaml';
    private const FILE_INVERSE = __DIR__ . '/../../../fixtures/library/mappings/iso42001_to_eu-ai-act_v2.0.yaml';

    private const VALID_RELATIONSHIPS = ['equivalent', 'superset', 'subset', 'partial_overlap', 'related'];
    private const VALID_CONFIDENCES   = ['high', 'medium', 'low'];

    /** @var array<mixed> */
    private static array $forward = [];

    /** @var array<mixed> */
    private static array $inverse = [];

    public static function setUpBeforeClass(): void
    {
        self::$forward = Yaml::parseFile(self::FILE_FORWARD);
        self::$inverse = Yaml::parseFile(self::FILE_INVERSE);
    }

    // -------------------------------------------------------------------------
    // File existence
    // -------------------------------------------------------------------------

    #[Test]
    public function forward_mapping_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::FILE_FORWARD);
        self::assertFileIsReadable(self::FILE_FORWARD);
    }

    #[Test]
    public function inverse_mapping_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::FILE_INVERSE);
        self::assertFileIsReadable(self::FILE_INVERSE);
    }

    // -------------------------------------------------------------------------
    // Top-level schema structure
    // -------------------------------------------------------------------------

    #[Test]
    public function forward_has_required_top_level_keys(): void
    {
        self::assertArrayHasKey('schema_version', self::$forward);
        self::assertArrayHasKey('library', self::$forward);
        self::assertArrayHasKey('mappings', self::$forward);
    }

    #[Test]
    public function inverse_has_required_top_level_keys(): void
    {
        self::assertArrayHasKey('schema_version', self::$inverse);
        self::assertArrayHasKey('library', self::$inverse);
        self::assertArrayHasKey('mappings', self::$inverse);
    }

    // -------------------------------------------------------------------------
    // CODES ALIGNMENT — core guard against DB mismatch
    // -------------------------------------------------------------------------

    #[Test]
    public function forward_source_framework_matches_db_code(): void
    {
        $actual = self::$forward['library']['source_framework'] ?? null;
        self::assertSame(
            self::DB_CODE_EU_AI_ACT,
            $actual,
            sprintf(
                'eu-ai-act_to_iso42001_v2.0.yaml source_framework "%s" does not match DB code "%s" '
                . '(LoadEuAiActFullCommand uses findOneBy([\'code\' => \'EU-AI-ACT\']))',
                (string) $actual,
                self::DB_CODE_EU_AI_ACT,
            ),
        );
    }

    #[Test]
    public function forward_target_framework_matches_db_code(): void
    {
        $actual = self::$forward['library']['target_framework'] ?? null;
        self::assertSame(
            self::DB_CODE_ISO_42001,
            $actual,
            sprintf(
                'eu-ai-act_to_iso42001_v2.0.yaml target_framework "%s" does not match DB code "%s" '
                . '(LoadIso42001FullCommand uses findOneBy([\'code\' => \'ISO42001\']))',
                (string) $actual,
                self::DB_CODE_ISO_42001,
            ),
        );
    }

    #[Test]
    public function inverse_source_framework_matches_db_code(): void
    {
        $actual = self::$inverse['library']['source_framework'] ?? null;
        self::assertSame(
            self::DB_CODE_ISO_42001,
            $actual,
            sprintf(
                'iso42001_to_eu-ai-act_v2.0.yaml source_framework "%s" does not match DB code "%s"',
                (string) $actual,
                self::DB_CODE_ISO_42001,
            ),
        );
    }

    #[Test]
    public function inverse_target_framework_matches_db_code(): void
    {
        $actual = self::$inverse['library']['target_framework'] ?? null;
        self::assertSame(
            self::DB_CODE_EU_AI_ACT,
            $actual,
            sprintf(
                'iso42001_to_eu-ai-act_v2.0.yaml target_framework "%s" does not match DB code "%s"',
                (string) $actual,
                self::DB_CODE_EU_AI_ACT,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Coverage thresholds (accidental-truncation guard)
    // -------------------------------------------------------------------------

    #[Test]
    public function forward_mapping_has_minimum_coverage(): void
    {
        self::assertGreaterThanOrEqual(
            30,
            count(self::$forward['mappings'] ?? []),
            'eu-ai-act_to_iso42001_v2.0 must have >=30 mapping entries',
        );
    }

    #[Test]
    public function inverse_mapping_has_minimum_coverage(): void
    {
        self::assertGreaterThanOrEqual(
            30,
            count(self::$inverse['mappings'] ?? []),
            'iso42001_to_eu-ai-act_v2.0 must have >=30 mapping entries',
        );
    }

    // -------------------------------------------------------------------------
    // Per-entry field validation — forward direction
    // -------------------------------------------------------------------------

    #[Test]
    public function forward_entries_have_required_fields(): void
    {
        foreach (self::$forward['mappings'] as $i => $entry) {
            $pos = "entry #{$i}";
            self::assertArrayHasKey('source', $entry, "Missing 'source' in forward {$pos}");
            self::assertArrayHasKey('target', $entry, "Missing 'target' in forward {$pos}");
            self::assertArrayHasKey('relationship', $entry, "Missing 'relationship' in forward {$pos}");
            self::assertArrayHasKey('confidence', $entry, "Missing 'confidence' in forward {$pos}");
            self::assertNotEmpty($entry['source'], "Empty 'source' in forward {$pos}");
            self::assertNotEmpty($entry['target'], "Empty 'target' in forward {$pos}");
        }
    }

    #[Test]
    public function forward_entries_have_valid_relationship_values(): void
    {
        foreach (self::$forward['mappings'] as $i => $entry) {
            self::assertContains(
                $entry['relationship'] ?? null,
                self::VALID_RELATIONSHIPS,
                sprintf(
                    'Invalid relationship "%s" at forward entry #%d (source=%s)',
                    (string) ($entry['relationship'] ?? ''),
                    $i,
                    (string) ($entry['source'] ?? ''),
                ),
            );
        }
    }

    #[Test]
    public function forward_entries_have_valid_confidence_values(): void
    {
        foreach (self::$forward['mappings'] as $i => $entry) {
            self::assertContains(
                $entry['confidence'] ?? null,
                self::VALID_CONFIDENCES,
                sprintf(
                    'Invalid confidence "%s" at forward entry #%d (source=%s)',
                    (string) ($entry['confidence'] ?? ''),
                    $i,
                    (string) ($entry['source'] ?? ''),
                ),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Per-entry field validation — inverse direction
    // -------------------------------------------------------------------------

    #[Test]
    public function inverse_entries_have_required_fields(): void
    {
        foreach (self::$inverse['mappings'] as $i => $entry) {
            $pos = "entry #{$i}";
            self::assertArrayHasKey('source', $entry, "Missing 'source' in inverse {$pos}");
            self::assertArrayHasKey('target', $entry, "Missing 'target' in inverse {$pos}");
            self::assertArrayHasKey('relationship', $entry, "Missing 'relationship' in inverse {$pos}");
            self::assertArrayHasKey('confidence', $entry, "Missing 'confidence' in inverse {$pos}");
            self::assertNotEmpty($entry['source'], "Empty 'source' in inverse {$pos}");
            self::assertNotEmpty($entry['target'], "Empty 'target' in inverse {$pos}");
        }
    }

    #[Test]
    public function inverse_entries_have_valid_relationship_values(): void
    {
        foreach (self::$inverse['mappings'] as $i => $entry) {
            self::assertContains(
                $entry['relationship'] ?? null,
                self::VALID_RELATIONSHIPS,
                sprintf(
                    'Invalid relationship "%s" at inverse entry #%d (source=%s)',
                    (string) ($entry['relationship'] ?? ''),
                    $i,
                    (string) ($entry['source'] ?? ''),
                ),
            );
        }
    }

    #[Test]
    public function inverse_entries_have_valid_confidence_values(): void
    {
        foreach (self::$inverse['mappings'] as $i => $entry) {
            self::assertContains(
                $entry['confidence'] ?? null,
                self::VALID_CONFIDENCES,
                sprintf(
                    'Invalid confidence "%s" at inverse entry #%d (source=%s)',
                    (string) ($entry['confidence'] ?? ''),
                    $i,
                    (string) ($entry['source'] ?? ''),
                ),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Inverse symmetry:
    // Every (src, tgt) pair in the forward file must appear as (tgt, src) in the
    // inverse file. The inverse file may carry additional pairs (extended coverage).
    // -------------------------------------------------------------------------

    #[Test]
    public function every_forward_pair_has_matching_inverse_entry(): void
    {
        /** @var array<string, true> $inverseIndex */
        $inverseIndex = [];
        foreach (self::$inverse['mappings'] as $entry) {
            $key = ($entry['source'] ?? '') . '|||' . ($entry['target'] ?? '');
            $inverseIndex[$key] = true;
        }

        $missing = [];
        foreach (self::$forward['mappings'] as $entry) {
            $fwdSrc = (string) ($entry['source'] ?? '');
            $fwdTgt = (string) ($entry['target'] ?? '');
            // In the inverse file roles are swapped: source=ISO42001 ID, target=EU-AI-ACT ID
            $lookupKey = $fwdTgt . '|||' . $fwdSrc;
            if (!isset($inverseIndex[$lookupKey])) {
                $missing[] = "({$fwdSrc} -> {$fwdTgt})";
            }
        }

        self::assertEmpty(
            $missing,
            'Forward pairs without matching inverse entry: ' . implode(', ', $missing),
        );
    }

    // -------------------------------------------------------------------------
    // Lifecycle state must be published for v2.0
    // -------------------------------------------------------------------------

    #[Test]
    public function forward_lifecycle_state_is_published(): void
    {
        $state = self::$forward['library']['lifecycle']['state'] ?? null;
        self::assertSame(
            'published',
            $state,
            'eu-ai-act_to_iso42001_v2.0 lifecycle.state must be "published"',
        );
    }

    #[Test]
    public function inverse_lifecycle_state_is_published(): void
    {
        $state = self::$inverse['library']['lifecycle']['state'] ?? null;
        self::assertSame(
            'published',
            $state,
            'iso42001_to_eu-ai-act_v2.0 lifecycle.state must be "published"',
        );
    }
}
