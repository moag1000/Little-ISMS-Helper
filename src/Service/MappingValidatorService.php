<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * Validiert YAML-Library-Mapping-Payloads bevor sie persistiert werden.
 * Returns ValidationResult mit Errors (blocking) + Warnings (informational).
 */
class MappingValidatorService
{
    /** Framework whose catalogue groups requirements by Baustein (category = Baustein code). */
    private const BAUSTEIN_FRAMEWORK = 'BSI_GRUNDSCHUTZ';
    /** A Baustein code: layer + numbering without an Anforderung suffix, e.g. ORP.4 or OPS.1.1.3. */
    private const BAUSTEIN_PATTERN = '/^[A-Z]{2,4}(\.\d+)+$/';

    public const ALLOWED_LIFECYCLE_STATES = ['draft', 'review', 'approved', 'published', 'deprecated'];
    public const ALLOWED_RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];
    public const ALLOWED_CONFIDENCES = ['low', 'medium', 'high'];
    public const ALLOWED_METHODOLOGY_TYPES = [
        'text_comparison_with_expert_review',
        'tag_based',
        'published_official_mapping',
        'official_published_crosswalk',
        'community_consensus',
        'machine_assisted_with_review',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Validiert einen geparsten YAML-Mapping-Payload.
     *
     * @param array $payload Geparste YAML (top-level keys: schema_version, library, mappings)
     * @return array{errors: list<string>, warnings: list<string>}
     */
    /**
     * Normalises mapping entries to one source→target pair each.
     *
     * Most library fixtures write a singular `target:`. The tisax_to_* family
     * writes a plural `targets: [...]` list instead, which neither this
     * validator nor MappingLibraryLoader could read — those mappings were
     * silently unimportable. Rows whose `targets` list is empty carry no
     * mapping fact (the source control has no anchor in the target framework)
     * and are dropped rather than treated as null-target gap markers.
     *
     * @param  array<int|string, mixed> $mappings
     * @return list<array<string, mixed>>
     */
    public static function expandEntries(array $mappings): array
    {
        $out = [];
        foreach ($mappings as $entry) {
            if (!is_array($entry) || !array_key_exists('targets', $entry) || array_key_exists('target', $entry)) {
                $out[] = $entry;
                continue;
            }

            $targets = $entry['targets'];
            unset($entry['targets']);
            foreach ((array) $targets as $target) {
                $out[] = ['target' => $target] + $entry;
            }
        }

        return $out;
    }

    public function validate(array $payload): array
    {
        $errors = [];
        $warnings = [];

        // 1. Top-Level-Struktur
        if (!isset($payload['schema_version'])) {
            $errors[] = "Missing 'schema_version' (current: '1.1').";
        }
        $library = $payload['library'] ?? null;
        $mappings = $payload['mappings'] ?? null;
        if (is_array($mappings)) {
            $mappings = self::expandEntries($mappings);
        }
        if (!is_array($library)) {
            $errors[] = "Missing or non-array 'library' block.";
            return ['errors' => $errors, 'warnings' => $warnings];
        }
        if (!is_array($mappings)) {
            $errors[] = "Missing or non-array 'mappings' block.";
        }
        if (($library['type'] ?? null) !== 'mapping') {
            $errors[] = "library.type must be 'mapping'.";
        }

        // 2. Pflichtfelder Library
        foreach (['source_framework', 'target_framework', 'version', 'effective_from'] as $req) {
            if (empty($library[$req])) {
                $errors[] = "library.{$req} is required.";
            }
        }

        // 3. Provenance-Pflicht
        $prov = $library['provenance'] ?? null;
        if (!is_array($prov) || empty($prov['primary_source'])) {
            $errors[] = "library.provenance.primary_source is required (anonymous mappings rejected).";
        }

        // 4. Methodology-Pflicht
        $meth = $library['methodology'] ?? null;
        if (!is_array($meth) || empty($meth['type'])) {
            $errors[] = "library.methodology.type is required.";
        } elseif (!in_array($meth['type'], self::ALLOWED_METHODOLOGY_TYPES, true)) {
            $errors[] = sprintf(
                "library.methodology.type '%s' invalid; allowed: %s.",
                $meth['type'],
                implode(', ', self::ALLOWED_METHODOLOGY_TYPES),
            );
        }
        if (is_array($meth) && empty($meth['description'])) {
            $warnings[] = "library.methodology.description is empty — Score-Penalty wird angewendet.";
        }

        // 5. Lifecycle
        $lifecycle = $library['lifecycle']['state'] ?? null;
        if ($lifecycle !== null && !in_array($lifecycle, self::ALLOWED_LIFECYCLE_STATES, true)) {
            $errors[] = sprintf(
                "library.lifecycle.state '%s' invalid; allowed: %s.",
                $lifecycle,
                implode(', ', self::ALLOWED_LIFECYCLE_STATES),
            );
        }

        // 6. Frameworks existieren in DB?
        $sourceCode = $library['source_framework'] ?? null;
        $targetCode = $library['target_framework'] ?? null;
        $sourceFw = $sourceCode ? $this->resolveFramework((string) $sourceCode) : null;
        $targetFw = $targetCode ? $this->resolveFramework((string) $targetCode) : null;
        if ($sourceCode && !$sourceFw) {
            $errors[] = "Source framework '{$sourceCode}' not found in DB. Load it first.";
        }
        if ($targetCode && !$targetFw) {
            $errors[] = "Target framework '{$targetCode}' not found in DB. Load it first.";
        }

        // 7. Per-Eintrag-Validierung
        if (is_array($mappings)) {
            $coveredSourceIds = [];
            $unresolved = ['source' => [], 'target' => []];
            foreach ($mappings as $i => $entry) {
                $prefix = "mappings[{$i}]";
                if (!is_array($entry)) {
                    $errors[] = "{$prefix} not an array.";
                    continue;
                }
                if (empty($entry['source'])) {
                    $errors[] = "{$prefix}.source required.";
                    continue;
                }
                $targetValue = $entry['target'] ?? null;
                $targetIsNull = $targetValue === null;
                if (!$targetIsNull && $targetValue === '') {
                    $errors[] = "{$prefix}.target empty (use 'null' for explicit no-equivalent gap markers).";
                    continue;
                }
                if ($targetIsNull) {
                    $rel = $entry['relationship'] ?? null;
                    if ($rel !== null && !in_array($rel, ['superset', 'related'], true)) {
                        $errors[] = "{$prefix}.target=null only allowed with relationship 'superset' or 'related' (gap marker), got '{$rel}'.";
                    }
                    if (empty($entry['gap_warning'])) {
                        $warnings[] = "{$prefix}.target=null without gap_warning — please document why no equivalent exists.";
                    }
                }
                if (isset($entry['relationship']) && !in_array($entry['relationship'], self::ALLOWED_RELATIONSHIPS, true)) {
                    $errors[] = "{$prefix}.relationship '{$entry['relationship']}' invalid; allowed: " . implode(', ', self::ALLOWED_RELATIONSHIPS) . ".";
                }
                if (isset($entry['confidence']) && !in_array($entry['confidence'], self::ALLOWED_CONFIDENCES, true)) {
                    $errors[] = "{$prefix}.confidence '{$entry['confidence']}' invalid; allowed: " . implode(', ', self::ALLOWED_CONFIDENCES) . ".";
                }
                if (empty($entry['rationale'])) {
                    $warnings[] = "{$prefix}.rationale empty — Auditor-Argumentation fehlt.";
                }

                // Source/Target-IDs in DB? An unknown ID only disqualifies its own pair —
                // MappingLibraryLoader skips it. Rejecting the whole file here used to
                // drop every valid pair of a library because of one stale ID.
                if ($sourceFw && !$this->requirementExists($sourceFw, (string) $entry['source'])) {
                    $unresolved['source'][(string) $entry['source']] = true;
                }
                if (!$targetIsNull && $targetFw && !$this->requirementExists($targetFw, (string) $targetValue)) {
                    $unresolved['target'][(string) $targetValue] = true;
                }

                $coveredSourceIds[(string) $entry['source']] = true;
            }

            foreach (['source' => $sourceCode, 'target' => $targetCode] as $side => $code) {
                if ($unresolved[$side] !== []) {
                    $ids = array_keys($unresolved[$side]);
                    $warnings[] = sprintf(
                        '%d %s ID(s) not found in framework \'%s\' — pairs skipped: %s%s',
                        count($ids),
                        $side,
                        (string) $code,
                        implode(', ', array_slice($ids, 0, 10)),
                        count($ids) > 10 ? ', …' : '',
                    );
                }
            }

            // 8. Coverage-Warnung wenn < 50 %
            if ($sourceFw) {
                $totalSourceItems = count($this->requirementRepository->findBy(['framework' => $sourceFw]));
                if ($totalSourceItems > 0) {
                    $coveragePct = (count($coveredSourceIds) / $totalSourceItems) * 100;
                    if ($coveragePct < 50) {
                        $warnings[] = sprintf(
                            'Coverage nur %.0f%% (%d/%d source-items) — MQS-Score wird leiden.',
                            $coveragePct,
                            count($coveredSourceIds),
                            $totalSourceItems,
                        );
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function resolveFramework(string $codeOrId): ?ComplianceFramework
    {
        return $this->frameworkRepository->findOneBy(['code' => $codeOrId])
            ?? $this->frameworkRepository->findOneBy(['name' => $codeOrId]);
    }

    private function requirementExists(ComplianceFramework $fw, string $identifier): bool
    {
        return $this->resolveRequirement($fw, $identifier) !== null;
    }

    /**
     * Resolve a fixture requirement ID to the catalogue row. Shared with
     * {@see MappingLibraryLoader} so validation and import agree on every pair.
     *
     * Order: the ID as written; then prefixed with the framework code (several
     * loaders store `ISO27001-4.1` / `NIS2-21.2.a` while official crosswalks cite
     * `4.1` / `21.2.a` — the prefixed form is unique per framework); then, for
     * IT-Grundschutz only, a Baustein code (`ORP.4`) resolves to the Baustein's
     * representative requirement — its first catalogue row, the same rule
     * {@see \App\Service\Bsi\PanelVerdictApplier} uses. The official BSI
     * crosswalk maps many ISO controls to whole Bausteine; the catalogue holds
     * Anforderungen only, so without this rule those rows never reached the DB.
     */
    public function resolveRequirement(ComplianceFramework $fw, string $identifier): ?ComplianceRequirement
    {
        if ($identifier === '') {
            return null;
        }
        $code = (string) $fw->getCode();
        $candidates = [$identifier];
        if ($code !== '' && !str_starts_with($identifier, $code . '-')) {
            $candidates[] = $code . '-' . $identifier;
        }
        foreach ($candidates as $candidate) {
            $requirement = $this->requirementRepository->findOneBy(['framework' => $fw, 'requirementId' => $candidate]);
            if ($requirement !== null) {
                return $requirement;
            }
        }

        if ($code === self::BAUSTEIN_FRAMEWORK && preg_match(self::BAUSTEIN_PATTERN, $identifier) === 1) {
            return $this->requirementRepository->findOneBy(
                ['framework' => $fw, 'category' => $identifier],
                ['id' => 'ASC'],
            );
        }

        return null;
    }
}
