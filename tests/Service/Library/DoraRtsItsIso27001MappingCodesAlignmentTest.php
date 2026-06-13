<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment guard for the DORA Level-2 (RTS/ITS) → ISO 27001:2022 mapping.
 *
 * This is the audit-detail layer beneath the Level-1 DORA↔ISO mapping: it maps
 * the granular RTS provisions (loaded by App\Command\LoadDoraRtsItsFullCommand
 * into the DORA framework) to ISO 27001:2022 Annex A controls.
 *
 * Verification scope (modeled on DoraIso27001MappingCodesAlignmentTest):
 *  1. File exists and parses; required top-level keys present.
 *  2. Framework codes match DB codes ('DORA' / 'ISO27001') — exact string.
 *     MappingLibraryLoader performs exact-string comparison against DB codes;
 *     a single-character mismatch silently skips ALL pairs at import.
 *  3. Every source id carries an RTS-/ITS- prefix (Level-2 convention).
 *  4. Every target is a real ISO 27001:2022 Annex A control id (A.5/6/7/8.x).
 *  5. relationship is one of the allowed enum values.
 *  6. confidence is one of high/medium/low.
 *  7. Required per-row fields (source/target/relationship/confidence/rationale).
 *  8. >= 40 mapping pairs (audit-detail depth requirement).
 *  9. No duplicate source+target pairs.
 * 10. Anti-silent-skip: every source id exactly matches a requirementId
 *     declared by LoadDoraRtsItsFullCommand.
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class DoraRtsItsIso27001MappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE = self::MAPPING_DIR . '/dora-rts-its_to_iso27001-2022_v1.0.yaml';

    private const COMMAND_FILE = __DIR__ . '/../../../src/Command/LoadDoraRtsItsFullCommand.php';

    /** Canonical DB code for the DORA framework (RTS provisions live in DORA). */
    private const DB_CODE_DORA = 'DORA';

    /** Canonical DB code for ISO 27001:2022. */
    private const DB_CODE_ISO27001 = 'ISO27001';

    /** @var list<string> allowed relationship enum values */
    private const RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];

    /** @var list<string> allowed confidence values */
    private const CONFIDENCES = ['high', 'medium', 'low'];

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $data = Yaml::parseFile(self::FILE);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mappings(): array
    {
        $data = $this->load();

        return array_values($data['mappings'] ?? []);
    }

    /**
     * Build the set of all requirementIds the loader command actually persists.
     * Parses the array keys of the form 'RTS-...' / 'ITS-...' in the source file.
     *
     * @return array<string, true>
     */
    private function loaderSourceIds(): array
    {
        self::assertFileExists(self::COMMAND_FILE, 'LoadDoraRtsItsFullCommand.php must exist');
        $php = (string) file_get_contents(self::COMMAND_FILE);

        // Match keys 'RTS-...' => or 'ITS-...' => (string value OR array value).
        preg_match_all("/'((?:RTS|ITS)-[A-Za-z0-9.\\-]+)'\\s*=>/", $php, $matches);

        $ids = [];
        foreach ($matches[1] as $id) {
            $ids[$id] = true;
        }

        return $ids;
    }

    #[Test]
    public function file_exists_and_has_required_keys(): void
    {
        self::assertFileExists(self::FILE, 'DORA RTS/ITS → ISO27001 mapping file missing');
        self::assertFileIsReadable(self::FILE);

        $data = $this->load();
        self::assertArrayHasKey('schema_version', $data);
        self::assertArrayHasKey('library', $data);
        self::assertArrayHasKey('mappings', $data);
    }

    #[Test]
    public function framework_codes_match_db_codes(): void
    {
        $data = $this->load();

        self::assertSame(
            self::DB_CODE_DORA,
            (string) ($data['library']['source_framework'] ?? ''),
            'source_framework must equal DB code "DORA" — a mismatch silently skips ALL pairs at import.',
        );
        self::assertSame(
            self::DB_CODE_ISO27001,
            (string) ($data['library']['target_framework'] ?? ''),
            'target_framework must equal DB code "ISO27001" — a mismatch silently skips ALL pairs at import.',
        );
    }

    #[Test]
    public function source_ids_carry_rts_or_its_prefix(): void
    {
        $violations = [];
        foreach ($this->mappings() as $idx => $entry) {
            $source = (string) ($entry['source'] ?? '');
            if (!str_starts_with($source, 'RTS-') && !str_starts_with($source, 'ITS-')) {
                $violations[] = sprintf('mapping[%d].source = "%s"', $idx, $source);
            }
        }

        self::assertEmpty(
            $violations,
            'Every source id must carry an "RTS-"/"ITS-" Level-2 prefix. Violations: '
                . implode(', ', $violations),
        );
    }

    #[Test]
    public function target_ids_are_real_annex_a_controls(): void
    {
        $annex = $this->annexAControls();

        $violations = [];
        foreach ($this->mappings() as $idx => $entry) {
            $target = (string) ($entry['target'] ?? '');
            if (!isset($annex[$target])) {
                $violations[] = sprintf('mapping[%d].target = "%s"', $idx, $target);
            }
            // cross_refs (if any) must also be valid Annex A controls.
            foreach (($entry['cross_refs'] ?? []) as $cr) {
                if (!isset($annex[(string) $cr])) {
                    $violations[] = sprintf('mapping[%d].cross_refs has invalid "%s"', $idx, (string) $cr);
                }
            }
        }

        self::assertEmpty(
            $violations,
            'Every target / cross_ref must be a valid ISO 27001:2022 Annex A control id. Violations: '
                . implode(', ', $violations),
        );
    }

    #[Test]
    public function relationship_and_confidence_use_valid_enums(): void
    {
        $badRel = [];
        $badConf = [];
        foreach ($this->mappings() as $idx => $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            $conf = (string) ($entry['confidence'] ?? '');
            if (!in_array($rel, self::RELATIONSHIPS, true)) {
                $badRel[] = sprintf('mapping[%d].relationship = "%s"', $idx, $rel);
            }
            if (!in_array($conf, self::CONFIDENCES, true)) {
                $badConf[] = sprintf('mapping[%d].confidence = "%s"', $idx, $conf);
            }
        }

        self::assertEmpty($badRel, 'Invalid relationship enum values: ' . implode(', ', $badRel));
        self::assertEmpty($badConf, 'Invalid confidence values: ' . implode(', ', $badConf));
    }

    #[Test]
    public function each_mapping_has_required_fields(): void
    {
        $violations = [];
        foreach ($this->mappings() as $idx => $entry) {
            foreach (['source', 'target', 'relationship', 'confidence', 'rationale'] as $field) {
                if (!isset($entry[$field]) || trim((string) $entry[$field]) === '') {
                    $violations[] = sprintf('mapping[%d] missing/empty "%s"', $idx, $field);
                }
            }
        }

        self::assertEmpty($violations, 'Rows missing required fields: ' . implode(', ', $violations));
    }

    #[Test]
    public function mapping_has_at_least_40_pairs(): void
    {
        $count = count($this->mappings());
        self::assertGreaterThanOrEqual(
            40,
            $count,
            sprintf('Expected >= 40 Level-2 RTS→ISO pairs (audit-detail depth). Got %d.', $count),
        );
    }

    #[Test]
    public function no_duplicate_source_target_pairs(): void
    {
        $seen = [];
        $dups = [];
        foreach ($this->mappings() as $entry) {
            $key = (string) ($entry['source'] ?? '') . '||' . (string) ($entry['target'] ?? '');
            if (isset($seen[$key])) {
                $dups[] = $key;
            }
            $seen[$key] = true;
        }

        self::assertEmpty($dups, 'Duplicate source+target pairs: ' . implode(', ', $dups));
    }

    #[Test]
    public function every_source_id_matches_a_loaded_requirement_id(): void
    {
        $loaded = $this->loaderSourceIds();
        self::assertNotEmpty($loaded, 'Could not parse any RTS/ITS ids from LoadDoraRtsItsFullCommand.');

        $violations = [];
        foreach ($this->mappings() as $idx => $entry) {
            $source = (string) ($entry['source'] ?? '');
            if (!isset($loaded[$source])) {
                $violations[] = sprintf('mapping[%d].source = "%s"', $idx, $source);
            }
        }

        self::assertEmpty(
            $violations,
            'Anti-silent-skip: every source must exactly match a requirementId loaded by '
                . 'LoadDoraRtsItsFullCommand. Unresolved: ' . implode(', ', $violations),
        );
    }

    #[Test]
    public function provenance_primary_source_is_present(): void
    {
        $data = $this->load();
        self::assertNotEmpty(
            (string) ($data['library']['provenance']['primary_source'] ?? ''),
            'library.provenance.primary_source must not be empty (validator rejects anonymous mappings).',
        );
    }

    /**
     * The 93 ISO 27001:2022 Annex A controls: A.5.1–A.5.37, A.6.1–A.6.8,
     * A.7.1–A.7.14, A.8.1–A.8.34.
     *
     * @return array<string, true>
     */
    private function annexAControls(): array
    {
        $controls = [];
        foreach ([5 => 37, 6 => 8, 7 => 14, 8 => 34] as $clause => $max) {
            for ($i = 1; $i <= $max; $i++) {
                $controls[sprintf('A.%d.%d', $clause, $i)] = true;
            }
        }

        return $controls;
    }
}
