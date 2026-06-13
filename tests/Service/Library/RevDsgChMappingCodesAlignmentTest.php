<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment guard for the revDSG-CH → GDPR mapping file.
 *
 * Verification scope:
 *  1. File exists and is parseable YAML with required top-level keys.
 *  2. Framework codes match exactly:
 *       source_framework: 'REVDSG-CH'  (DB code set by LoadRevDsgChFullCommand)
 *       target_framework: 'GDPR'       (DB code set by LoadGdprFullCommand)
 *     MappingLibraryLoader performs exact-string findOneBy(['code'=>...]) —
 *     a single-character mismatch silently skips ALL pairs at import time.
 *  3. All source IDs carry the canonical 'REVDSG-Art.' prefix
 *     (set by LoadRevDsgChFullCommand::ARTICLES key schema).
 *  4. All target IDs carry the canonical 'Art.' prefix
 *     (set by LoadGdprFullCommand::ARTICLES key schema).
 *  5. Required fields per mapping entry: source, target, relationship, confidence, rationale.
 *  6. Relationship enum restricted to valid values.
 *  7. Minimum 20 mapping pairs.
 *  8. No duplicate source IDs.
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 *
 * Framework DB codes:
 *   REVDSG-CH → compliance_framework.code = 'REVDSG-CH' (LoadRevDsgChFullCommand)
 *   GDPR      → compliance_framework.code = 'GDPR'      (LoadGdprFullCommand)
 */
final class RevDsgChMappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const MAPPING_FILE = self::MAPPING_DIR . '/revdsg-ch_to_gdpr_v1.0.yaml';

    /** Canonical DB code for the revDSG framework. */
    private const DB_CODE_REVDSG = 'REVDSG-CH';

    /** Canonical DB code for the GDPR framework. */
    private const DB_CODE_GDPR = 'GDPR';

    /** Valid relationship enum values (MappingValidatorService constraint). */
    private const VALID_RELATIONSHIPS = [
        'equivalent',
        'subset',
        'superset',
        'related',
        'partial_overlap',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 1 — file existence + YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function file_exists_and_has_required_yaml_keys(): void
    {
        self::assertFileExists(
            self::MAPPING_FILE,
            'revDSG-CH → GDPR mapping file missing: ' . self::MAPPING_FILE,
        );
        self::assertFileIsReadable(self::MAPPING_FILE);

        $data = Yaml::parseFile(self::MAPPING_FILE);
        self::assertIsArray($data, 'YAML parse must return an array for: ' . basename(self::MAPPING_FILE));
        self::assertArrayHasKey('schema_version', $data, "Missing 'schema_version' in mapping file.");
        self::assertArrayHasKey('library', $data, "Missing 'library' key in mapping file.");
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in mapping file.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 2 — framework codes match DB codes (CRITICAL: MappingLibraryLoader
    // does exact-string findOneBy — any mismatch = all pairs silent-skipped)
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function source_framework_code_matches_db_code(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $actual = (string) ($data['library']['source_framework'] ?? '');

        self::assertSame(
            self::DB_CODE_REVDSG,
            $actual,
            sprintf(
                '%s: source_framework "%s" must exactly equal DB code "%s". '
                . 'MappingLibraryLoader performs exact findOneBy — a mismatch silently '
                . 'skips ALL mapping pairs at import time.',
                basename(self::MAPPING_FILE),
                $actual,
                self::DB_CODE_REVDSG,
            ),
        );
    }

    #[Test]
    public function target_framework_code_matches_db_code(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $actual = (string) ($data['library']['target_framework'] ?? '');

        self::assertSame(
            self::DB_CODE_GDPR,
            $actual,
            sprintf(
                '%s: target_framework "%s" must exactly equal DB code "%s". '
                . 'MappingLibraryLoader performs exact findOneBy — a mismatch silently '
                . 'skips ALL mapping pairs at import time.',
                basename(self::MAPPING_FILE),
                $actual,
                self::DB_CODE_GDPR,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 3 — source IDs use 'REVDSG-Art.' prefix
    // LoadRevDsgChFullCommand::ARTICLES uses 'REVDSG-Art.N' keys.
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function source_ids_use_revdsg_art_prefix(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $violations = [];

        foreach (($data['mappings'] ?? []) as $idx => $entry) {
            $sourceId = (string) ($entry['source'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            if (!str_starts_with($sourceId, 'REVDSG-Art.')) {
                $violations[] = sprintf('mapping[%d].source = "%s"', $idx, $sourceId);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: all source IDs must use the "REVDSG-Art." prefix '
                . '(LoadRevDsgChFullCommand requirementId schema). '
                . 'Non-conformant IDs will fail findOneBy resolution at import. '
                . 'Violations: %s',
                basename(self::MAPPING_FILE),
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 4 — target IDs use 'Art.' prefix (GDPR loader uses 'Art.N' keys)
    // LoadGdprFullCommand::ARTICLES uses 'Art.N' keys as requirementId.
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function target_ids_use_gdpr_art_prefix(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $violations = [];

        foreach (($data['mappings'] ?? []) as $idx => $entry) {
            $targetId = (string) ($entry['target'] ?? '');
            // null targets are allowed (out-of-scope / gap entries)
            if ($targetId === '' || $targetId === 'null') {
                continue;
            }
            if (!str_starts_with($targetId, 'Art.')) {
                $violations[] = sprintf('mapping[%d].target = "%s"', $idx, $targetId);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: all non-null target IDs must use the "Art." prefix '
                . '(LoadGdprFullCommand requirementId schema e.g. Art.5, Art.32). '
                . 'Violations: %s',
                basename(self::MAPPING_FILE),
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 5 — required fields present in every entry
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function all_entries_have_required_fields(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $missing = [];

        foreach (($data['mappings'] ?? []) as $idx => $entry) {
            foreach (['source', 'relationship', 'confidence', 'rationale'] as $field) {
                if (!array_key_exists($field, $entry) || $entry[$field] === null || $entry[$field] === '') {
                    $missing[] = sprintf('mapping[%d].%s', $idx, $field);
                }
            }
            // 'target' may be null for gap/out-of-scope entries — only check key exists
            if (!array_key_exists('target', $entry)) {
                $missing[] = sprintf('mapping[%d].target (key missing)', $idx);
            }
        }

        self::assertEmpty(
            $missing,
            sprintf(
                '%s: every mapping entry must have source, target (may be null), '
                . 'relationship, confidence and rationale. Missing: %s',
                basename(self::MAPPING_FILE),
                implode(', ', $missing),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 6 — relationship values are from the valid enum set
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function relationship_values_are_valid_enum(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $invalid = [];

        foreach (($data['mappings'] ?? []) as $idx => $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            if ($rel !== '' && !in_array($rel, self::VALID_RELATIONSHIPS, true)) {
                $invalid[] = sprintf('mapping[%d].relationship = "%s"', $idx, $rel);
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: relationship must be one of [%s]. Invalid: %s',
                basename(self::MAPPING_FILE),
                implode(', ', self::VALID_RELATIONSHIPS),
                implode(', ', $invalid),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 7 — minimum 20 mapping pairs
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function mapping_has_at_least_20_pairs(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            20,
            $count,
            sprintf(
                '%s: expected >= 20 mapping pairs covering the main revDSG→GDPR '
                . 'crosswalk (principles, security, processor, RoPA, transfers, '
                . 'information duties, DPIA, breach notification, data-subject rights). '
                . 'Got %d.',
                basename(self::MAPPING_FILE),
                $count,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 8 — no duplicate source IDs
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function no_duplicate_source_ids(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $sourceIds = [];
        $duplicates = [];

        foreach (($data['mappings'] ?? []) as $idx => $entry) {
            $sourceId = (string) ($entry['source'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            if (isset($sourceIds[$sourceId])) {
                $duplicates[] = sprintf('"%s" (at mapping[%d] and mapping[%d])', $sourceId, $sourceIds[$sourceId], $idx);
            } else {
                $sourceIds[$sourceId] = $idx;
            }
        }

        self::assertEmpty(
            $duplicates,
            sprintf(
                '%s: duplicate source IDs found — each revDSG article should appear '
                . 'at most once as a source entry. Duplicates: %s',
                basename(self::MAPPING_FILE),
                implode(', ', $duplicates),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 9 — provenance.primary_source is non-empty
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function library_has_non_empty_provenance_primary_source(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $primarySource = (string) ($data['library']['provenance']['primary_source'] ?? '');

        self::assertNotEmpty(
            $primarySource,
            sprintf(
                '%s: library.provenance.primary_source must not be empty '
                . '(MappingValidatorService rejects anonymous mappings).',
                basename(self::MAPPING_FILE),
            ),
        );
    }
}
