<?php

declare(strict_types=1);

namespace App\Service\Compliance\Pairs;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;

/**
 * Coverage and transitive compliance of every framework pair, computed from data loaded once
 * (one mapping query, one fulfilment query, one count per framework) — S31. The single place of the two
 * derivations: `ComplianceMappingRepository::calculateFrameworkCoverage()` / `getTransitiveCompliance()`
 * call the same static functions for one pair, so screen, export and API never disagree.
 */
final readonly class CrossFrameworkPairSet
{
    /**
     * @param array<int, array<int, list<ComplianceMapping>>> $mappings [sourceFwId][targetFwId] (best first)
     * @param array<int, int> $topLevelCounts framework id => top-level requirement count
     * @param array<int, int> $sourcePercentages requirement id => tenant fulfilment %
     */
    public function __construct(
        private array $mappings,
        private array $topLevelCounts,
        private array $sourcePercentages,
    ) {
    }

    /** @return list<ComplianceMapping> */
    public function mappings(ComplianceFramework $source, ComplianceFramework $target): array
    {
        return $this->mappings[$source->getId()][$target->getId()] ?? [];
    }

    public function topLevelCount(ComplianceFramework $framework): int
    {
        return $this->topLevelCounts[$framework->getId()] ?? 0;
    }

    /** @return array{source_framework: string, target_framework: string, total_target_requirements: int, covered_requirements: int, coverage_percentage: float, strong_mappings: int, partial_mappings: int, weak_mappings: int} */
    public function coverage(ComplianceFramework $source, ComplianceFramework $target): array
    {
        return self::coverageOf($source, $target, $this->mappings($source, $target), $this->topLevelCounts[$target->getId()] ?? 0);
    }

    /** @return array{source_framework: string, target_framework: string, requirements_helped: int, average_transitive_benefit: float, total_benefit: float, contributions: array} */
    public function transitive(ComplianceFramework $source, ComplianceFramework $target): array
    {
        return self::transitiveOf(
            $source,
            $target,
            $this->mappings($source, $target),
            $this->sourcePercentages,
            $this->topLevelCounts[$target->getId()] ?? 0,
        );
    }

    /**
     * The requirement of $other that $requirement maps to (strongest forward mapping), else the strongest
     * mapping from $other onto it.
     *
     * @return array{requirement: ComplianceRequirement, quality: ?int}|null
     */
    public function counterpart(ComplianceRequirement $requirement, ComplianceFramework $other): ?array
    {
        $own = $requirement->getFramework();
        if ($own === null) {
            return null;
        }
        foreach ($this->mappings($own, $other) as $mapping) {
            if ($mapping->getSourceRequirement()?->getId() === $requirement->getId() && $mapping->getTargetRequirement() !== null) {
                return ['requirement' => $mapping->getTargetRequirement(), 'quality' => $mapping->getMappingPercentage()];
            }
        }
        foreach ($this->mappings($other, $own) as $mapping) {
            if ($mapping->getTargetRequirement()?->getId() === $requirement->getId() && $mapping->getSourceRequirement() !== null) {
                return ['requirement' => $mapping->getSourceRequirement(), 'quality' => $mapping->getMappingPercentage()];
            }
        }

        return null;
    }

    /**
     * How comprehensively the source framework covers the target (best mapping per target requirement,
     * capped at 100 %, over the target's top-level requirements).
     *
     * @param iterable<ComplianceMapping> $mappings
     * @return array{source_framework: string, target_framework: string, total_target_requirements: int, covered_requirements: int, coverage_percentage: float, strong_mappings: int, partial_mappings: int, weak_mappings: int}
     */
    public static function coverageOf(
        ComplianceFramework $source,
        ComplianceFramework $target,
        iterable $mappings,
        int $targetTopLevel,
    ): array {
        $covered = [];
        foreach ($mappings as $mapping) {
            $targetReqId = $mapping->getTargetRequirement()->getId();
            if (!isset($covered[$targetReqId]) || $covered[$targetReqId] < $mapping->getMappingPercentage()) {
                $covered[$targetReqId] = $mapping->getMappingPercentage();
            }
        }
        $total = 0;
        foreach ($covered as $percentage) {
            $total += min(100, $percentage);
        }

        return [
            'source_framework' => $source->getName(),
            'target_framework' => $target->getName(),
            'total_target_requirements' => $targetTopLevel,
            'covered_requirements' => count($covered),
            'coverage_percentage' => $targetTopLevel > 0 ? round($total / $targetTopLevel, 2) : 0,
            'strong_mappings' => count(array_filter($covered, static fn (int $c): bool => $c >= 100)),
            'partial_mappings' => count(array_filter($covered, static fn (int $c): bool => $c >= 50 && $c < 100)),
            'weak_mappings' => count(array_filter($covered, static fn (int $c): bool => $c < 50)),
        ];
    }

    /**
     * How fulfilling the source framework helps the target ("compliance transfer"): source fulfilment ×
     * mapping strength, best contribution per target requirement, over the target's top-level requirements.
     *
     * @param iterable<ComplianceMapping> $mappings
     * @param array<int, int> $sourcePercentages requirement id => tenant fulfilment %
     * @return array{source_framework: string, target_framework: string, requirements_helped: int, average_transitive_benefit: float, total_benefit: float, contributions: array}
     */
    public static function transitiveOf(
        ComplianceFramework $source,
        ComplianceFramework $target,
        iterable $mappings,
        array $sourcePercentages,
        int $targetTopLevel,
    ): array {
        $contributions = [];
        foreach ($mappings as $mapping) {
            $sourcePercentage = $sourcePercentages[$mapping->getSourceRequirement()?->getId()] ?? 0;
            $transitive = round($sourcePercentage * $mapping->getMappingPercentage() / 100, 2);
            if ($transitive > 0) {
                $contributions[] = [
                    'source_req' => $mapping->getSourceRequirement()->getRequirementId(),
                    'target_req' => $mapping->getTargetRequirement()->getRequirementId(),
                    'mapping_strength' => $mapping->getMappingPercentage(),
                    'source_fulfillment' => $sourcePercentage,
                    'transitive_contribution' => $transitive,
                ];
            }
        }
        $helped = [];
        foreach ($contributions as $contribution) {
            $key = $contribution['target_req'];
            if (!isset($helped[$key]) || $helped[$key] < $contribution['transitive_contribution']) {
                $helped[$key] = $contribution['transitive_contribution'];
            }
        }
        $totalBenefit = array_sum($helped);

        return [
            'source_framework' => $source->getName(),
            'target_framework' => $target->getName(),
            'requirements_helped' => count($helped),
            'average_transitive_benefit' => $targetTopLevel > 0 ? round($totalBenefit / $targetTopLevel, 2) : 0,
            'total_benefit' => round($totalBenefit, 2),
            'contributions' => $contributions,
        ];
    }
}
