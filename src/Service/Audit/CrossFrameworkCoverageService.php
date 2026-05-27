<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditFinding;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\InternalAudit;
use App\Repository\ComplianceMappingRepository;

/**
 * C4-B4 (CM_DATA_REUSE): cross-framework coverage analysis for multi-framework
 * audits. A single audit may cover ISO 27001 + NIS2 + DORA in one pass; this
 * service computes which findings transitively close requirements across every
 * covered framework via the ComplianceMapping graph.
 *
 * Pure analysis service — no persistence, no flush. Pass an {@see InternalAudit}
 * with its findings + linked requirements already hydrated; the service walks
 * the mapping graph once and returns a structured {@see CoverageReport}.
 *
 * ROI: turns 3 separate 5-day audits into 1 combined 5-day audit while keeping
 * the per-framework evidence trail auditors expect.
 */
final readonly class CrossFrameworkCoverageService
{
    /**
     * Mapping strength threshold below which a mapping is ignored.
     * 50 % = "partial" per the ComplianceMapping::updateMappingType() ladder.
     * Below that we treat the relationship as too weak to claim coverage.
     */
    public const int MIN_MAPPING_PERCENTAGE = 50;

    public function __construct(
        private ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * Compute the full coverage report for an audit.
     *
     * Walks each linked requirement on every finding, follows outbound (and
     * bidirectional inbound) ComplianceMappings, and collects the set of
     * transitively-covered requirements per framework in scope.
     *
     * Performance: ComplianceMappings are batch-loaded once for the full set
     * of linked requirements (two queries total, not 2 × N) — see the
     * `findMappingsBy{Source,Target}Requirements` batch helpers.
     */
    public function buildReport(InternalAudit $audit): CoverageReport
    {
        $frameworksInScope = $audit->getAllScopedFrameworks();
        if ($frameworksInScope === []) {
            return CoverageReport::empty();
        }

        /** @var array<int, ComplianceFramework> $frameworksById */
        $frameworksById = [];
        foreach ($frameworksInScope as $framework) {
            $frameworksById[(int) $framework->id] = $framework;
        }

        // Collect every distinct linked requirement once so we can batch-load
        // mappings ahead of the per-finding loop.
        /** @var array<int, ComplianceRequirement> $requirementsById */
        $requirementsById = [];
        foreach ($audit->getStructuredFindings() as $finding) {
            foreach ($finding->getLinkedRequirements() as $requirement) {
                if ($requirement instanceof ComplianceRequirement && $requirement->getId() !== null) {
                    $requirementsById[(int) $requirement->getId()] = $requirement;
                }
            }
        }
        $requirementList = array_values($requirementsById);

        $outboundByReqId = $this->mappingRepository->findMappingsBySourceRequirements($requirementList);
        $inboundByReqId = $this->mappingRepository->findMappingsByTargetRequirements($requirementList);

        // direct[frameworkId][requirementId] = list<AuditFinding>
        $direct = [];
        // transitive[frameworkId][requirementId] = list<TransitiveCoverage>
        $transitive = [];

        foreach ($audit->getStructuredFindings() as $finding) {
            foreach ($finding->getLinkedRequirements() as $requirement) {
                if (!$requirement instanceof ComplianceRequirement) {
                    continue;
                }
                $framework = $requirement->getFramework();
                if (!$framework instanceof ComplianceFramework) {
                    continue;
                }
                $fwId = (int) $framework->id;
                $reqId = (int) $requirement->getId();

                $direct[$fwId][$reqId][] = $finding;

                foreach ($this->resolveTransitiveCoverage($requirement, $frameworksById, $outboundByReqId, $inboundByReqId) as $coverage) {
                    $targetFwId = (int) $coverage->targetFramework->id;
                    $targetReqId = (int) $coverage->targetRequirement->getId();
                    $coverage->finding = $finding;
                    $transitive[$targetFwId][$targetReqId][] = $coverage;
                }
            }
        }

        return new CoverageReport(
            frameworks: array_values($frameworksById),
            directCoverage: $direct,
            transitiveCoverage: $transitive,
        );
    }

    /**
     * For a single source requirement, return every transitive coverage edge
     * into another framework in scope. Honours bidirectional mappings and the
     * {@see MIN_MAPPING_PERCENTAGE} cut-off.
     *
     * @param array<int, ComplianceFramework>           $frameworksById
     * @param array<int, list<ComplianceMapping>>       $outboundByReqId  pre-loaded outbound mappings keyed by source-req id
     * @param array<int, list<ComplianceMapping>>       $inboundByReqId   pre-loaded inbound  mappings keyed by target-req id
     * @return list<TransitiveCoverage>
     */
    private function resolveTransitiveCoverage(
        ComplianceRequirement $source,
        array $frameworksById,
        array $outboundByReqId,
        array $inboundByReqId,
    ): array {
        $out = [];
        $sourceId = (int) $source->getId();

        foreach ($outboundByReqId[$sourceId] ?? [] as $mapping) {
            $coverage = $this->buildCoverage(
                mapping: $mapping,
                target: $mapping->getTargetRequirement(),
                frameworksById: $frameworksById,
                direction: 'outbound',
            );
            if ($coverage !== null) {
                $out[] = $coverage;
            }
        }

        // Inbound edges where the source happens to be the target of a
        // bidirectional mapping — they let coverage flow the other way.
        foreach ($inboundByReqId[$sourceId] ?? [] as $mapping) {
            if (!$mapping->isBidirectional()) {
                continue;
            }
            $coverage = $this->buildCoverage(
                mapping: $mapping,
                target: $mapping->getSourceRequirement(),
                frameworksById: $frameworksById,
                direction: 'inbound',
            );
            if ($coverage !== null) {
                $out[] = $coverage;
            }
        }

        return $out;
    }

    /**
     * @param array<int, ComplianceFramework> $frameworksById
     */
    private function buildCoverage(
        ComplianceMapping $mapping,
        ?ComplianceRequirement $target,
        array $frameworksById,
        string $direction,
    ): ?TransitiveCoverage {
        if (!$target instanceof ComplianceRequirement) {
            return null;
        }
        $targetFramework = $target->getFramework();
        if (!$targetFramework instanceof ComplianceFramework) {
            return null;
        }
        $targetFwId = (int) $targetFramework->id;
        if (!isset($frameworksById[$targetFwId])) {
            return null;
        }

        $percentage = $mapping->getFinalPercentage();
        if ($percentage < self::MIN_MAPPING_PERCENTAGE) {
            return null;
        }

        return new TransitiveCoverage(
            targetFramework: $frameworksById[$targetFwId],
            targetRequirement: $target,
            mapping: $mapping,
            direction: $direction,
            percentage: $percentage,
        );
    }
}
