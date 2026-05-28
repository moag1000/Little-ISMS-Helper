<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;

/**
 * Cross-Framework Lookup Service
 *
 * Provides forward, reverse, and transitive requirement lookup across compliance frameworks.
 * Uses a symmetric-query strategy: one row per pair in the DB, queried in both directions
 * in PHP. Bidirectional flag on ComplianceMapping controls whether reverse lookups are
 * permitted for a given mapping row.
 *
 * Coverage provenance tags (direct_forward, direct_reverse, transitive_via_<CODE>)
 * are returned so callers can distinguish how a relationship was inferred.
 *
 * In-memory per-request cache (private array) avoids repeated DB queries within a
 * single page render without the complexity of external cache invalidation.
 */
final class CrossFrameworkLookupService
{
    /** @var array<string, list<array{mapping: ComplianceMapping, direction: string, via: list<string>, depth: int}>> */
    private array $cache = [];

    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {}

    /**
     * Forward lookup: find all mappings where $source is the source requirement.
     *
     * @param string|null $targetFrameworkCode Filter results to a specific framework
     * @return list<array{mapping: ComplianceMapping, direction: 'forward', via: list<string>, depth: int}>
     */
    public function findMappingsForward(
        ComplianceRequirement $source,
        ?string $targetFrameworkCode = null,
    ): array {
        $rows = $this->mappingRepository->findByEitherSourceOrTarget($source, $targetFrameworkCode);

        $result = [];
        foreach ($rows as $mapping) {
            if ($mapping->getSourceRequirement()?->getId() === $source->getId()) {
                // Only include rows matching the framework filter
                if ($targetFrameworkCode !== null) {
                    $code = $mapping->getTargetRequirement()?->getFramework()?->getCode();
                    if ($code !== $targetFrameworkCode) {
                        continue;
                    }
                }
                $result[] = [
                    'mapping'   => $mapping,
                    'direction' => 'forward',
                    'via'       => [],
                    'depth'     => 1,
                ];
            }
        }

        return $result;
    }

    /**
     * Reverse lookup: find all mappings where $target is the target requirement,
     * but only when the mapping is flagged bidirectional.
     *
     * @param string|null $sourceFrameworkCode Filter results to a specific framework
     * @return list<array{mapping: ComplianceMapping, direction: 'reverse', via: list<string>, depth: int}>
     */
    public function findMappingsReverse(
        ComplianceRequirement $target,
        ?string $sourceFrameworkCode = null,
    ): array {
        $rows = $this->mappingRepository->findByEitherSourceOrTarget($target, $sourceFrameworkCode);

        $result = [];
        foreach ($rows as $mapping) {
            if ($mapping->getTargetRequirement()?->getId() === $target->getId()
                && $mapping->isBidirectional()
            ) {
                if ($sourceFrameworkCode !== null) {
                    $code = $mapping->getSourceRequirement()?->getFramework()?->getCode();
                    if ($code !== $sourceFrameworkCode) {
                        continue;
                    }
                }
                $result[] = [
                    'mapping'   => $mapping,
                    'direction' => 'reverse',
                    'via'       => [],
                    'depth'     => 1,
                ];
            }
        }

        return $result;
    }

    /**
     * Find all direct equivalent requirements in other frameworks.
     *
     * Unions forward + reverse lookups. Deduplicates by the "other requirement" ID,
     * keeping the entry with the highest mapping percentage (strongest wins).
     *
     * @param string|null $otherFrameworkCode Filter to a specific framework
     * @return list<array{requirement: ComplianceRequirement, mapping: ComplianceMapping, direction: string, via: list<string>, depth: int}>
     */
    public function findEquivalents(
        ComplianceRequirement $req,
        ?string $otherFrameworkCode = null,
    ): array {
        $cacheKey = $req->getId() . ':' . ($otherFrameworkCode ?? '*');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $forward = $this->findMappingsForward($req, $otherFrameworkCode);
        $reverse = $this->findMappingsReverse($req, $otherFrameworkCode);

        // Map each entry to a {requirement, mapping, direction, via, depth} shape
        $byOtherReqId = [];

        foreach ($forward as $entry) {
            $otherReq = $entry['mapping']->getTargetRequirement();
            if ($otherReq === null) {
                continue;
            }
            $id = $otherReq->getId();
            if (!isset($byOtherReqId[$id])
                || $entry['mapping']->getFinalPercentage() > $byOtherReqId[$id]['mapping']->getFinalPercentage()
            ) {
                $byOtherReqId[$id] = [
                    'requirement' => $otherReq,
                    'mapping'     => $entry['mapping'],
                    'direction'   => $entry['direction'],
                    'via'         => $entry['via'],
                    'depth'       => $entry['depth'],
                ];
            }
        }

        foreach ($reverse as $entry) {
            $otherReq = $entry['mapping']->getSourceRequirement();
            if ($otherReq === null) {
                continue;
            }
            $id = $otherReq->getId();
            if (!isset($byOtherReqId[$id])
                || $entry['mapping']->getFinalPercentage() > $byOtherReqId[$id]['mapping']->getFinalPercentage()
            ) {
                $byOtherReqId[$id] = [
                    'requirement' => $otherReq,
                    'mapping'     => $entry['mapping'],
                    'direction'   => $entry['direction'],
                    'via'         => $entry['via'],
                    'depth'       => $entry['depth'],
                ];
            }
        }

        $result = array_values($byOtherReqId);
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Transitive DFS walk up to $maxDepth hops.
     *
     * Returns all reachable equivalent requirements, with provenance path.
     * Cycle guard via visited set (requirement IDs as keys).
     *
     * @return list<array{requirement: ComplianceRequirement, mapping: ComplianceMapping, direction: string, via: list<string>, depth: int}>
     */
    public function findTransitiveEquivalents(
        ComplianceRequirement $req,
        int $maxDepth = 2,
    ): array {
        $visited = [$req->getId() => true];
        return $this->walkTransitive($req, $maxDepth, 0, [], $visited);
    }

    /**
     * Return direct equivalents grouped by target framework code.
     * Within each framework, sorted by mapping percentage DESC.
     * Frameworks sorted alphabetically by code.
     *
     * @return array<string, list<array{requirement: ComplianceRequirement, mapping: ComplianceMapping, direction: string, via: list<string>, depth: int}>>
     */
    public function findEquivalentsGroupedByFramework(
        ComplianceRequirement $req,
    ): array {
        $equivalents = $this->findEquivalents($req);

        $grouped = [];
        foreach ($equivalents as $entry) {
            $code = $entry['requirement']->getFramework()?->getCode() ?? 'UNKNOWN';
            $grouped[$code][] = $entry;
        }

        // Sort within each framework by percentage DESC
        foreach ($grouped as $code => $entries) {
            usort($grouped[$code], static fn (array $a, array $b): int =>
                $b['mapping']->getFinalPercentage() <=> $a['mapping']->getFinalPercentage()
            );
        }

        // Sort frameworks alphabetically
        ksort($grouped);

        return $grouped;
    }

    /**
     * Compute provenance tags for a requirement's cross-framework relationships.
     *
     * Returns tags like: direct_forward, direct_reverse, transitive_via_ISO27001-2022
     *
     * @return list<string>
     */
    public function computeProvenanceTags(
        ComplianceRequirement $req,
        int $maxDepth = 2,
    ): array {
        $tags = [];

        $direct = $this->findEquivalents($req);
        foreach ($direct as $entry) {
            $tags[] = 'direct_' . $entry['direction'];
        }

        // Transitive — depth > 1
        $transitive = $this->findTransitiveEquivalents($req, $maxDepth);
        foreach ($transitive as $entry) {
            if ($entry['depth'] > 1) {
                foreach ($entry['via'] as $viaCode) {
                    $tag = 'transitive_via_' . $viaCode;
                    if (!in_array($tag, $tags, true)) {
                        $tags[] = $tag;
                    }
                }
            }
        }

        return array_unique($tags);
    }

    /**
     * DFS recursive walk for transitive equivalents.
     *
     * @param list<string>        $path    Framework codes traversed so far (for provenance)
     * @param array<int, true>    $visited Requirement IDs already visited (cycle guard)
     * @return list<array{requirement: ComplianceRequirement, mapping: ComplianceMapping, direction: string, via: list<string>, depth: int}>
     */
    private function walkTransitive(
        ComplianceRequirement $current,
        int $maxDepth,
        int $depth,
        array $path,
        array &$visited,
    ): array {
        if ($depth >= $maxDepth) {
            return [];
        }

        $results = [];
        $directEquivalents = $this->findEquivalents($current);

        foreach ($directEquivalents as $entry) {
            $otherReq = $entry['requirement'];
            $otherId  = $otherReq->getId();

            if (isset($visited[$otherId])) {
                continue; // cycle guard
            }
            $visited[$otherId] = true;

            $newPath = array_merge($path, [$current->getFramework()?->getCode() ?? 'UNKNOWN']);

            $results[] = [
                'requirement' => $otherReq,
                'mapping'     => $entry['mapping'],
                'direction'   => $entry['direction'],
                'via'         => $newPath,
                'depth'       => $depth + 1,
            ];

            // Recurse
            $deeper = $this->walkTransitive($otherReq, $maxDepth, $depth + 1, $newPath, $visited);
            foreach ($deeper as $deepEntry) {
                $results[] = $deepEntry;
            }
        }

        return $results;
    }
}
