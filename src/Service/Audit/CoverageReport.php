<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditFinding;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;

/**
 * Immutable report returned by {@see CrossFrameworkCoverageService::buildReport()}.
 *
 * Holds two nested maps keyed by framework-id → requirement-id:
 *   - directCoverage:     finding linked the requirement explicitly
 *   - transitiveCoverage: finding closed the requirement via ComplianceMapping
 *
 * Convenience accessors return per-framework rolled-up data ready for Twig
 * rendering or XLSX export.
 */
final readonly class CoverageReport
{
    /**
     * @param list<ComplianceFramework>                                            $frameworks
     * @param array<int, array<int, list<AuditFinding>>>                           $directCoverage
     * @param array<int, array<int, list<TransitiveCoverage>>>                     $transitiveCoverage
     */
    public function __construct(
        public array $frameworks,
        public array $directCoverage,
        public array $transitiveCoverage,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public function isEmpty(): bool
    {
        return $this->frameworks === [];
    }

    /**
     * True when more than one framework is in scope — gate the cross-framework
     * section in the UI.
     */
    public function isMultiFramework(): bool
    {
        return count($this->frameworks) > 1;
    }

    /**
     * Number of requirements directly covered in the given framework.
     */
    public function directCount(ComplianceFramework $framework): int
    {
        $fwId = (int) $framework->id;
        return count($this->directCoverage[$fwId] ?? []);
    }

    /**
     * Number of requirements transitively covered in the given framework via
     * mappings (excluding requirements that are also directly covered).
     */
    public function transitiveOnlyCount(ComplianceFramework $framework): int
    {
        $fwId = (int) $framework->id;
        $direct = $this->directCoverage[$fwId] ?? [];
        $transitive = $this->transitiveCoverage[$fwId] ?? [];

        $only = 0;
        foreach (array_keys($transitive) as $reqId) {
            if (!isset($direct[$reqId])) {
                $only++;
            }
        }
        return $only;
    }

    /**
     * Total covered requirement count in the given framework (direct ∪ transitive).
     */
    public function totalCoveredCount(ComplianceFramework $framework): int
    {
        $fwId = (int) $framework->id;
        $direct = $this->directCoverage[$fwId] ?? [];
        $transitive = $this->transitiveCoverage[$fwId] ?? [];
        return count($direct + $transitive);
    }

    /**
     * Per-framework summary rows for Twig rendering. Each entry:
     *   ['framework' => ComplianceFramework, 'direct' => int, 'transitive' => int, 'total' => int]
     *
     * @return list<array{framework: ComplianceFramework, direct: int, transitive: int, total: int}>
     */
    public function summaryRows(): array
    {
        $rows = [];
        foreach ($this->frameworks as $framework) {
            $rows[] = [
                'framework' => $framework,
                'direct' => $this->directCount($framework),
                'transitive' => $this->transitiveOnlyCount($framework),
                'total' => $this->totalCoveredCount($framework),
            ];
        }
        return $rows;
    }

    /**
     * Findings that cover at least one requirement in framework $target via a
     * mapping (transitive). Keyed by finding id to dedupe.
     *
     * @return list<array{finding: AuditFinding, target_requirement: ComplianceRequirement, mapping_percentage: int}>
     */
    public function transitiveRowsFor(ComplianceFramework $target): array
    {
        $fwId = (int) $target->id;
        $rows = [];
        foreach ($this->transitiveCoverage[$fwId] ?? [] as $coverages) {
            foreach ($coverages as $coverage) {
                if (!$coverage->finding instanceof AuditFinding) {
                    continue;
                }
                $rows[] = [
                    'finding' => $coverage->finding,
                    'target_requirement' => $coverage->targetRequirement,
                    'mapping_percentage' => $coverage->percentage,
                ];
            }
        }
        return $rows;
    }

    /**
     * Estimated FTE-day saving vs. running one audit per framework
     * (assuming the audit-cycle baseline is 5 FTE-days per framework).
     *
     * Returns the floored saving when more than one framework is in scope.
     */
    public function estimatedFteDaySaving(int $perFrameworkBaselineDays = 5): int
    {
        $count = count($this->frameworks);
        if ($count < 2) {
            return 0;
        }
        // savings = (n-1) * baseline minus a 20 % reconciliation overhead
        $raw = ($count - 1) * $perFrameworkBaselineDays;
        return (int) floor($raw * 0.8);
    }
}
