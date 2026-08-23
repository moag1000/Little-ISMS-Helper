<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the state of `cross_refs:` in the shipped mapping library.
 *
 * `cross_refs` are advisory "see also" pointers. Unlike `source`/`target`
 * they are deliberately NOT imported — they mostly reference the SOURCE
 * framework, so importing them would create source→source edges. See
 * docs/COMPLIANCE_CATALOG_ARCHITECTURE.md §7.1.
 *
 * Because nothing consumes them at runtime, nothing noticed when one rotted.
 * This test is that "something": every cross_ref must either resolve against
 * the corpus of known ids for its source or target framework, or appear in the
 * reviewed allow-list below.
 *
 * The allow-list is not a dumping ground. Each entry was checked against the
 * actual regulation: DORA Art. 4/19/25/26/29/30 and GDPR Art. 6/7/14/16/18/
 * 21/34/39 all exist (verified against the isms-specialist and dpo-specialist
 * reference documents); BAIT chapters 2/9/10.1/10.4 exist in the circular.
 * They are simply provisions this library does not map yet — which is exactly
 * what an advisory pointer is for. A NEW unresolvable entry, by contrast, is
 * far more likely a typo, so it fails.
 */
final class MappingCrossRefIntegrityTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    /**
     * Citations that are valid in their regulation but not (yet) mapped here.
     *
     * @var array<string, list<string>>
     */
    private const REVIEWED_UNMAPPED = [
        'BAIT→DORA' => ['BAIT 10.1', 'BAIT 10.4', 'BAIT 2', 'BAIT 9'],
        'BSI-C5-2026→BSI-C5' => ['GC-01', 'GC-05'],
        'BSI-C5→BSI_GRUNDSCHUTZ' => ['MA-01'],
        'BSI-C5→EUCS' => ['A16-1', 'IM-02', 'OS-03'],
        'DORA→BAIT' => ['BAIT 10.1', 'BAIT 10.4', 'BAIT 2', 'BAIT 9'],
        'DORA→NIS2' => ['Art.19', 'Art.25', 'Art.26', 'DORA.Art.11.6', 'DORA.Art.5.4', 'ITS-Register-RT.04.02'],
        'DORA→NIS2UMSUCG' => ['Art.19', 'Art.25', 'Art.26', 'Art.29', 'Art.30'],
        'EU-AI-ACT→GDPR' => ['Art.34', 'Art.6'],
        'EU-AI-ACT→ISO42001' => ['A.2.3', 'A.2.4', 'A.6.2.5'],
        'EU-AI-ACT→NIS2' => ['Art.15-Cybersicherheit', 'Art.15-Robustheit'],
        'GDPR→EU-AI-ACT' => ['Art.34', 'Art.6'],
        'GDPR→ISO27018' => ['14.2.5'],
        'GDPR→ISO27701' => ['27701-A.7.4.2', '27701-A.8.4.2', '27701-B.7.2.2', '27701-B.8.2.2', '27701-B.8.5.3'],
        'ISO27018→GDPR' => ['Art.14', 'Art.16', 'Art.18', 'Art.21', 'Art.34', 'Art.39', 'Art.6', 'Art.7'],
        'ISO27701_2025→GDPR' => ['GDPR-31', 'GDPR-5.1.e', 'GDPR-5.2'],
        'ISO27701→GDPR' => ['27701-A.7.4.2', '27701-B.8.2.2', '27701-B.8.5.3'],
        'ISO42001→EU-AI-ACT' => ['A.2.3', 'A.2.4', 'A.6.2.5'],
        'KRITIS→NIS2UMSUCG' => ['§ 1'],
        'NIS2UMSUCG→DORA' => ['Art.19', 'Art.25', 'Art.26', 'Art.29', 'Art.30', 'Art.4'],
        'NIS2UMSUCG→KRITIS' => ['§ 30', '§ 30 Abs. 2 Nr. 3', '§ 31', '§ 32', '§ 33'],
        'NIS2→BSI_GRUNDSCHUTZ' => ['ISMS.2', 'OPS.3'],
        'NIS2→DORA' => ['Art.19', 'Art.25', 'Art.26', 'DORA.Art.11.6', 'DORA.Art.5.4'],
        'REVDSG-CH→GDPR' => ['Art.14', 'Art.16', 'Art.34'],
    ];

    #[Test]
    public function everyCrossRefResolvesOrIsReviewed(): void
    {
        [$universe, $files] = self::loadCorpus();

        $offenders = [];
        foreach ($files as $name => $data) {
            $library = $data['library'] ?? [];
            $source = $library['source_framework'] ?? null;
            $target = $library['target_framework'] ?? null;
            $pair = $source . '→' . $target;

            foreach (($data['mappings'] ?? []) as $index => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                foreach (($entry['cross_refs'] ?? []) as $crossRef) {
                    $value = trim((string) $crossRef);

                    $known = ($universe[$source] ?? [])[$value] ?? null;
                    $known ??= ($universe[$target] ?? [])[$value] ?? null;

                    if ($known !== null) {
                        continue;
                    }

                    if (in_array($value, self::REVIEWED_UNMAPPED[$pair] ?? [], true)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s mappings[%d]: "%s" (%s)', $name, $index, $value, $pair);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Unresolvable cross_refs.\nEither fix the citation, or — if it is a real provision this "
            . "library does not map yet — add it to REVIEWED_UNMAPPED after checking it against the "
            . "regulation itself.\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function theAllowListDoesNotRot(): void
    {
        [$universe, $files] = self::loadCorpus();

        $seen = [];
        foreach ($files as $data) {
            $library = $data['library'] ?? [];
            $pair = ($library['source_framework'] ?? null) . '→' . ($library['target_framework'] ?? null);
            foreach (($data['mappings'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach (($entry['cross_refs'] ?? []) as $crossRef) {
                    $seen[$pair][trim((string) $crossRef)] = true;
                }
            }
        }

        $stale = [];
        foreach (self::REVIEWED_UNMAPPED as $pair => $values) {
            foreach ($values as $value) {
                $stillUnresolved = !isset($seen[$pair][$value])
                    || isset($universe[explode('→', $pair)[0]][$value])
                    || isset($universe[explode('→', $pair)[1]][$value]);

                if ($stillUnresolved) {
                    $stale[] = $pair . ': ' . $value;
                }
            }
        }

        self::assertSame(
            [],
            $stale,
            "Allow-list entries that are no longer needed — the citation was removed or is now "
            . "mapped. Drop them so the list keeps meaning something.\n" . implode("\n", $stale),
        );
    }

    /**
     * @return array{0: array<string, array<string, true>>, 1: array<string, array<mixed>>}
     */
    private static function loadCorpus(): array
    {
        $universe = [];
        $files = [];

        foreach (glob(self::MAPPING_DIR . '/*.yaml') ?: [] as $path) {
            $data = Yaml::parseFile($path);
            if (!is_array($data)) {
                continue;
            }
            $files[basename($path)] = $data;

            $library = $data['library'] ?? [];
            $source = $library['source_framework'] ?? null;
            $target = $library['target_framework'] ?? null;

            foreach (($data['mappings'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if ($source !== null && isset($entry['source'])) {
                    $universe[$source][trim((string) $entry['source'])] = true;
                }
                if ($target === null) {
                    continue;
                }
                if (isset($entry['target'])) {
                    $universe[$target][trim((string) $entry['target'])] = true;
                }
                foreach (($entry['targets'] ?? []) as $multi) {
                    $value = is_array($multi) ? ($multi['target'] ?? null) : $multi;
                    if ($value !== null) {
                        $universe[$target][trim((string) $value)] = true;
                    }
                }
            }
        }

        return [$universe, $files];
    }
}
