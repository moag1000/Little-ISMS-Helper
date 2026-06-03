<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Completeness and correctness test for the legacy-id crosswalk fixture.
 *
 * Validates:
 *  1. The fixture parses correctly.
 *  2. Every entry has EITHER a 'target' (canonical 1.1.1 id) OR a documented
 *     'needs_human_review' reason — nothing silently dropped (no-silent-cap
 *     rule from spec §4.2).
 *  3. The deterministic 'ISA ' prefix-strip rule produces correct results
 *     on representative samples.
 *  4. Every deterministic chapter-stub entry is marked 'ISA-KAP-n'.
 *  5. Version and appliesToIsaVersion metadata is present.
 *  6. Every domain-prefixed entry has a non-empty 'domain' key (spec §9.2).
 *
 * @see fixtures/library/mappings/tisax-legacy-id-crosswalk.yaml
 * @see docs/superpowers/specs/2026-06-01-tisax-framework-consolidation-design.md §4.2, §9.2
 */
final class LegacyIdCrosswalkTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../../fixtures/library/mappings/tisax-legacy-id-crosswalk.yaml';

    /** Parsed fixture, loaded once per suite run. */
    private static array $fixture = [];

    protected function setUp(): void
    {
        if (self::$fixture === []) {
            self::$fixture = Yaml::parseFile(self::FIXTURE_PATH);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §1  File-level structure
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function fixture_file_exists_and_parses(): void
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Crosswalk fixture file must exist.');
        self::assertIsArray(self::$fixture, 'Fixture must parse to an array.');
        self::assertNotEmpty(self::$fixture, 'Fixture must not be empty.');
    }

    #[Test]
    public function fixture_has_required_metadata(): void
    {
        self::assertArrayHasKey('version', self::$fixture, 'version key is required (spec §9.2 G8).');
        self::assertArrayHasKey('appliesToIsaVersion', self::$fixture, 'appliesToIsaVersion is required (spec §9.2 G8).');
        self::assertSame(1, self::$fixture['version'], 'version must be integer 1 for the initial fixture.');
        self::assertStringContainsString('6', (string) self::$fixture['appliesToIsaVersion'], 'appliesToIsaVersion must reference VDA-ISA 6.x.');
    }

    #[Test]
    public function fixture_has_deterministic_rules_section(): void
    {
        self::assertArrayHasKey('deterministic_rules', self::$fixture, 'deterministic_rules section is required.');
        self::assertArrayHasKey('isa_prefix_strip', self::$fixture['deterministic_rules']);
        self::assertArrayHasKey('isa_kap_chapter_stubs', self::$fixture['deterministic_rules']);
    }

    #[Test]
    public function fixture_has_summary_section(): void
    {
        self::assertArrayHasKey('summary', self::$fixture, 'summary section is required for audit transparency.');
        $summary = self::$fixture['summary'];
        self::assertArrayHasKey('total_legacy_ids', $summary);
        self::assertArrayHasKey('needs_human_review', $summary);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §2  Deterministic rule: 'ISA x.y.z' → 'x.y.z'
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function isaStripSamples(): array
    {
        return [
            'simple control'         => ['ISA 1.1.1',  '1.1.1'],
            'two-digit sub-item'      => ['ISA 3.1.4',  '3.1.4'],
            'large chapter'           => ['ISA 5.2.7',  '5.2.7'],
            'prototype protection'    => ['ISA 8.1.3',  '8.1.3'],
            'data protection chapter' => ['ISA 9.5.2',  '9.5.2'],
        ];
    }

    #[Test]
    #[DataProvider('isaStripSamples')]
    public function isa_prefix_strip_rule_is_correct(string $legacy, string $expected): void
    {
        // Inline implementation of the deterministic rule so the test does not
        // depend on any PHP service class (the rule is applied by the console
        // command and/or the consolidation command — tested here via the rule
        // spec in the fixture).
        $rule = self::$fixture['deterministic_rules']['isa_prefix_strip'];
        $prefix = $rule['prefix'];

        self::assertStringStartsWith('ISA ', $legacy, "Sample '{$legacy}' must start with 'ISA '.");
        $canonical = substr($legacy, strlen($prefix));
        self::assertSame($expected, $canonical, "ISA-prefix strip of '{$legacy}' must yield '{$expected}'.");

        // Verify the fixture pattern matches
        self::assertSame('^ISA (\\d+\\.\\d+\\.\\d+)$', $rule['pattern']);
    }

    #[Test]
    public function isa_prefix_strip_rule_has_result_format(): void
    {
        $rule = self::$fixture['deterministic_rules']['isa_prefix_strip'];
        self::assertSame('x.y.z', $rule['result_format']);
        self::assertSame('ISA ', $rule['prefix']);
        self::assertSame('strip_prefix', $rule['transform']);
    }

    #[Test]
    public function isa_prefix_strip_rule_has_examples(): void
    {
        $rule = self::$fixture['deterministic_rules']['isa_prefix_strip'];
        self::assertArrayHasKey('examples', $rule);
        self::assertNotEmpty($rule['examples']);

        foreach ($rule['examples'] as $example) {
            self::assertArrayHasKey('legacy', $example);
            self::assertArrayHasKey('canonical', $example);
            // Verify the example is self-consistent
            $derived = substr($example['legacy'], strlen('ISA '));
            self::assertSame($example['canonical'], $derived, sprintf(
                "Example legacy='%s' must derive to canonical='%s' via prefix strip.",
                $example['legacy'],
                $example['canonical'],
            ));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §3  Chapter stub rule: 'ISA-KAP-n'
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function chapter_stub_rule_has_known_instances(): void
    {
        $rule = self::$fixture['deterministic_rules']['isa_kap_chapter_stubs'];
        self::assertArrayHasKey('known_instances', $rule, 'known_instances list is required for audit trail.');
        self::assertNotEmpty($rule['known_instances']);

        foreach ($rule['known_instances'] as $instance) {
            self::assertMatchesRegularExpression('/^ISA-KAP-\d+$/', $instance, "Chapter stub '{$instance}' must match ISA-KAP-n format.");
        }
    }

    #[Test]
    public function chapter_stub_rule_has_delete_action(): void
    {
        $rule = self::$fixture['deterministic_rules']['isa_kap_chapter_stubs'];
        self::assertArrayHasKey('action', $rule);
        self::assertStringContainsString('delete', $rule['action']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §4  Domain-prefixed entries: no-silent-cap rule
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns every entry from domain_prefixed_entries for parametrised tests.
     *
     * @return array<string, array{array<string,mixed>}>
     */
    public static function allDomainEntries(): array
    {
        // Parse fixture directly (static context, no setUp)
        $fixture = Yaml::parseFile(__DIR__ . '/../../../fixtures/library/mappings/tisax-legacy-id-crosswalk.yaml');
        $entries = $fixture['domain_prefixed_entries'] ?? [];
        $result  = [];
        foreach ($entries as $entry) {
            $key = $entry['legacy_id'] ?? uniqid('entry_', true);
            $result[$key] = [$entry];
        }
        return $result;
    }

    #[Test]
    #[DataProvider('allDomainEntries')]
    public function every_domain_entry_has_target_or_needs_human_review(array $entry): void
    {
        $id = $entry['legacy_id'] ?? '(unknown)';
        $hasTarget           = !empty($entry['target']);
        $hasNeedsHumanReview = isset($entry['status']) && $entry['status'] === 'needs_human_review';
        $hasReviewReason     = !empty($entry['review_reason']);

        self::assertTrue(
            $hasTarget || ($hasNeedsHumanReview && $hasReviewReason),
            "Entry '{$id}' violates the no-silent-cap rule: it must have either a 'target' canonical id, " .
            "or 'status: needs_human_review' with a non-empty 'review_reason'.",
        );
    }

    #[Test]
    #[DataProvider('allDomainEntries')]
    public function every_domain_entry_has_a_domain_facet(array $entry): void
    {
        $id = $entry['legacy_id'] ?? '(unknown)';
        self::assertArrayHasKey('domain', $entry, "Entry '{$id}' must have a 'domain' key (spec §9.2 domain-facet note).");
        self::assertNotEmpty($entry['domain'], "Entry '{$id}' 'domain' must not be empty.");
    }

    #[Test]
    #[DataProvider('allDomainEntries')]
    public function every_domain_entry_has_a_legacy_id(array $entry): void
    {
        self::assertArrayHasKey('legacy_id', $entry, 'Each domain entry must have a legacy_id key.');
        self::assertNotEmpty($entry['legacy_id']);
    }

    #[Test]
    #[DataProvider('allDomainEntries')]
    public function every_domain_entry_has_a_source_command(array $entry): void
    {
        $id = $entry['legacy_id'] ?? '(unknown)';
        self::assertArrayHasKey('source_command', $entry, "Entry '{$id}' must document its source command for traceability.");
        self::assertNotEmpty($entry['source_command']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §5  Representative sample assertions (deterministic spot checks)
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function domain_entries_section_is_not_empty(): void
    {
        self::assertArrayHasKey('domain_prefixed_entries', self::$fixture, 'domain_prefixed_entries section required.');
        self::assertNotEmpty(self::$fixture['domain_prefixed_entries'], 'domain_prefixed_entries must not be empty.');
    }

    /**
     * @return array<string, array{string, string}>  [label => [legacy_id, expected_domain]]
     */
    public static function expectedDomainSamples(): array
    {
        return [
            'INF-1.1 has domain INF'            => ['INF-1.1',  'INF'],
            'ACC-1.1 has domain ACC'             => ['ACC-1.1',  'ACC'],
            'BCM-1.1 has domain BCM'             => ['BCM-1.1',  'BCM'],
            'OPS-4.1 has domain OPS'             => ['OPS-4.1',  'OPS'],
            'PHY-1.1 has domain PHY'             => ['PHY-1.1',  'PHY'],
            'HRS-1.1 has domain HRS'             => ['HRS-1.1',  'HRS'],
            'SUP-1.1 has domain SUP'             => ['SUP-1.1',  'SUP'],
            'TISAX-CONF-SC-1.1 has domain CONF'  => ['TISAX-CONF-SC-1.1', 'CONF'],
            'TISAX-DATA-1.1 has domain DP'       => ['TISAX-DATA-1.1', 'DP'],
            'TISAX-AVAIL-VH-1.1 has domain AVAIL'=> ['TISAX-AVAIL-VH-1.1', 'AVAIL'],
            'TISAX-PROTO-GEN-1.1 has domain PROTO'=> ['TISAX-PROTO-GEN-1.1', 'PROTO'],
        ];
    }

    #[Test]
    #[DataProvider('expectedDomainSamples')]
    public function specific_entries_have_expected_domains(string $legacyId, string $expectedDomain): void
    {
        $entries = self::$fixture['domain_prefixed_entries'];
        $found = null;
        foreach ($entries as $entry) {
            if (($entry['legacy_id'] ?? '') === $legacyId) {
                $found = $entry;
                break;
            }
        }
        self::assertNotNull($found, "Entry for legacy_id='{$legacyId}' must exist in domain_prefixed_entries.");
        self::assertSame($expectedDomain, $found['domain'], "Entry '{$legacyId}' must have domain='{$expectedDomain}'.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §6  Summary statistics consistency
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function summary_needs_human_review_count_matches_actual_entries(): void
    {
        $summary = self::$fixture['summary'];
        $entries = self::$fixture['domain_prefixed_entries'];

        $actualNhr = 0;
        foreach ($entries as $entry) {
            if (isset($entry['status']) && $entry['status'] === 'needs_human_review') {
                $actualNhr++;
            }
        }

        self::assertSame(
            (int) $summary['needs_human_review'],
            $actualNhr,
            sprintf(
                'summary.needs_human_review (%d) must equal the actual count of needs_human_review entries (%d).',
                (int) $summary['needs_human_review'],
                $actualNhr,
            ),
        );
    }

    #[Test]
    public function chapter_stubs_count_is_six(): void
    {
        $rule = self::$fixture['deterministic_rules']['isa_kap_chapter_stubs'];
        self::assertCount(6, $rule['known_instances'], 'Exactly 6 ISA-KAP chapter stubs expected (ISA-KAP-1..6).');
    }

    #[Test]
    public function no_entry_is_silently_dropped_all_have_ids(): void
    {
        $entries = self::$fixture['domain_prefixed_entries'];
        $emptyIdCount = 0;
        foreach ($entries as $entry) {
            if (empty($entry['legacy_id'])) {
                $emptyIdCount++;
            }
        }
        self::assertSame(0, $emptyIdCount, 'No entry may have an empty legacy_id (no-silent-cap rule).');
    }
}
