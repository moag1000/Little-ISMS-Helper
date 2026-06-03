<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads VDA-ISA 6 Requirement-Level Metadata from the pre-seeded YAML fixture.
 *
 * LEGAL NOTE: The fixture contains ONLY boolean presence-flags (does control X
 * have Must/Sollte/Hoher Schutzbedarf/Sehr hoher Schutzbedarf content?) and
 * derived Assessment-Level applicability. NO licensed ENX text is stored.
 * Customers must read the actual requirement text from their own licensed
 * ENX workbook copy (https://portal.enx.com/).
 *
 * Data source: fixtures/library/metadata/tisax_requirement_levels_v1.0.yaml
 * Extractor: scripts/import/extract_vda_isa_requirement_metadata.php
 *
 * Cached per-request in $metadataIndex to avoid re-parsing YAML on every call.
 */
final class RequirementLevelMetadataLoader
{
    private const FIXTURE_RELATIVE = '/fixtures/library/metadata/tisax_requirement_levels_v1.0.yaml';

    /**
     * Per-request cache. Key: controlId (e.g. "1.1.1"), value: metadata array.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $metadataIndex = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /**
     * Return the level-metadata record for a given VDA-ISA control ID.
     *
     * The $controlId should be the bare x.x.x form (e.g. "1.1.1", "5.2.6").
     * Passing an "ISA " prefix (e.g. "ISA 1.1.1") is also supported — the
     * prefix is stripped before lookup.
     *
     * Returns:
     *   [
     *     'controlId'                  => '1.1.1',
     *     'levels' => [
     *       'must'               => bool,
     *       'should'             => bool,
     *       'high_protection'    => bool,
     *       'very_high_protection' => bool,
     *     ],
     *     'cell_lengths' => ['must' => int, 'should' => int, 'high' => int, 'very_high' => int],
     *     'suggested_assessment_levels' => ['AL1', 'AL2', 'AL3'],
     *     'protection_need_addenda'     => ['high', 'very_high'],  // subset
     *   ]
     *
     * Returns null when the control ID is unknown (outside the fixture).
     *
     * @return array<string, mixed>|null
     */
    public function getMetadataFor(string $controlId): ?array
    {
        $this->ensureLoaded();

        // Normalise: strip optional "ISA " prefix
        $normalised = preg_replace('/^ISA\s+/i', '', trim($controlId));

        return $this->metadataIndex[$normalised] ?? null;
    }

    /**
     * Return all control IDs present in the fixture.
     *
     * @return list<string>
     */
    public function allControlIds(): array
    {
        $this->ensureLoaded();
        return array_keys($this->metadataIndex ?? []);
    }

    /**
     * Return control IDs that have a specific level flag set.
     *
     * $flag must be one of: 'must', 'should', 'high_protection', 'very_high_protection'.
     *
     * @return list<string>
     */
    public function controlIdsWithLevel(string $flag): array
    {
        $this->ensureLoaded();
        $result = [];
        foreach ($this->metadataIndex ?? [] as $id => $meta) {
            if (($meta['levels'][$flag] ?? false) === true) {
                $result[] = $id;
            }
        }
        return $result;
    }

    /**
     * Return control IDs applicable to a specific Assessment Level.
     *
     * $al must be one of: 'AL1', 'AL2', 'AL3'.
     *
     * @return list<string>
     */
    public function controlIdsForAssessmentLevel(string $al): array
    {
        $this->ensureLoaded();
        $result = [];
        foreach ($this->metadataIndex ?? [] as $id => $meta) {
            if (in_array($al, $meta['suggested_assessment_levels'] ?? [], true)) {
                $result[] = $id;
            }
        }
        return $result;
    }

    /**
     * Return the fixture-level stats block.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $this->ensureLoaded();
        return $this->fixtureStats ?? [];
    }

    /** @var array<string, mixed> */
    private array $fixtureStats = [];

    // ─────────────────────────────────────────────────────────────────────────

    private function ensureLoaded(): void
    {
        if ($this->metadataIndex !== null) {
            return;
        }

        $fixturePath = $this->projectDir . self::FIXTURE_RELATIVE;

        if (!file_exists($fixturePath)) {
            // Graceful degradation — return empty index rather than hard failure
            // so that pages that optionally show these badges still render.
            $this->metadataIndex = [];
            return;
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($fixturePath);

        $this->fixtureStats = $data['metadata']['stats'] ?? [];

        $index = [];
        foreach ($data['controls'] ?? [] as $entry) {
            $id = (string) ($entry['controlId'] ?? '');
            if ($id === '') {
                continue;
            }
            $index[$id] = $entry;
        }

        $this->metadataIndex = $index;
    }
}
