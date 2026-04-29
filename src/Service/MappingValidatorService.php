<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * Validiert YAML-Library-Mapping-Payloads bevor sie persistiert werden.
 * Returns ValidationResult mit Errors (blocking) + Warnings (informational).
 */
class MappingValidatorService
{
    public const ALLOWED_LIFECYCLE_STATES = ['draft', 'review', 'approved', 'published', 'deprecated'];
    public const ALLOWED_RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];
    public const ALLOWED_CONFIDENCES = ['low', 'medium', 'high'];
    public const ALLOWED_METHODOLOGY_TYPES = [
        'text_comparison_with_expert_review',
        'tag_based',
        'published_official_mapping',
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
            foreach ($mappings as $i => $entry) {
                $prefix = "mappings[{$i}]";
                if (!is_array($entry)) {
                    $errors[] = "{$prefix} not an array.";
                    continue;
                }
                if (empty($entry['source']) || empty($entry['target'])) {
                    $errors[] = "{$prefix}.source and .target required.";
                    continue;
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

                // Source/Target-IDs in DB?
                if ($sourceFw && !$this->requirementExists($sourceFw, (string) $entry['source'])) {
                    $errors[] = "{$prefix}.source '{$entry['source']}' not found in framework '{$sourceCode}'.";
                }
                if ($targetFw && !$this->requirementExists($targetFw, (string) $entry['target'])) {
                    $errors[] = "{$prefix}.target '{$entry['target']}' not found in framework '{$targetCode}'.";
                }

                $coveredSourceIds[(string) $entry['source']] = true;
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
        return $this->requirementRepository->findOneBy([
            'framework' => $fw,
            'requirementId' => $identifier,
        ]) !== null;
    }
}
