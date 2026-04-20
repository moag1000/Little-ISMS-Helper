<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\KpiSnapshotRepository;

/**
 * Framework Maturity Service (Sprint 3 / C2).
 *
 * Computes CMMI-style maturity levels per ComplianceFramework so the
 * CM can report progress to the CISO beyond raw coverage-percentage.
 *
 * Levels:
 *   1 Initial                — Framework loaded, < 30 % implemented.
 *   2 Managed                — ≥ 60 % implemented, basic documentation.
 *   3 Defined                — ≥ 80 % implemented AND cross-framework
 *                              mappings present AND ≥ 1 internal audit
 *                              or management review logged.
 *   4 Quantitatively Managed — Level 3 conditions plus ≥ 6 months
 *                              KPI-snapshot history (trend data).
 *   5 Optimizing             — Level 4 conditions plus documented
 *                              continuous-improvement cycle (≥ 2
 *                              management reviews with actionItems).
 *
 * Empty framework (no requirements loaded) or empty tenant yields Level 0
 * *"n/a"* so the board-one-pager doesn't pretend progress where there
 * is none.
 *
 * Design note: the rules are intentionally conservative — the CM wants
 * maturity claims to survive an auditor's push-back. When in doubt, the
 * service emits the lower level plus an explicit `missing` reason list
 * so the CM can explain *why* the framework is not yet at the next
 * level.
 */
final class FrameworkMaturityService
{
    public const LEVEL_NA = 0;
    public const LEVEL_INITIAL = 1;
    public const LEVEL_MANAGED = 2;
    public const LEVEL_DEFINED = 3;
    public const LEVEL_QUANTITATIVELY_MANAGED = 4;
    public const LEVEL_OPTIMIZING = 5;

    public const LEVEL_LABEL = [
        self::LEVEL_NA => 'n/a',
        self::LEVEL_INITIAL => 'initial',
        self::LEVEL_MANAGED => 'managed',
        self::LEVEL_DEFINED => 'defined',
        self::LEVEL_QUANTITATIVELY_MANAGED => 'quantitatively_managed',
        self::LEVEL_OPTIMIZING => 'optimizing',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ?InternalAuditRepository $auditRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
    ) {
    }

    /**
     * @return array{
     *     level: int,
     *     label: string,
     *     coverage_pct: int,
     *     cross_mappings: int,
     *     audits: int,
     *     snapshot_months: int,
     *     missing: list<string>
     * }
     */
    public function computeForFramework(ComplianceFramework $framework, ?Tenant $tenant = null): array
    {
        $requirements = $this->requirementRepository->findByFramework($framework);
        $total = count($requirements);
        if ($total === 0) {
            return $this->emptyResult();
        }

        $coverage = $this->averageCoverage($requirements);
        $crossMappings = count($this->mappingRepository->findBy(['sourceRequirement' => $requirements]))
            + count($this->mappingRepository->findBy(['targetRequirement' => $requirements]));

        $auditsDone = 0;
        if ($this->auditRepository !== null) {
            try {
                $auditsDone = $this->countAudits($tenant);
            } catch (\Throwable) {
                $auditsDone = 0;
            }
        }

        $snapshotMonths = $this->snapshotHistoryMonths();

        $level = self::LEVEL_INITIAL;
        $missing = [];

        if ($coverage >= 60) {
            $level = self::LEVEL_MANAGED;
        } else {
            $missing[] = sprintf('coverage < 60%% (aktuell %d %%)', $coverage);
        }

        if ($level >= self::LEVEL_MANAGED) {
            if ($coverage >= 80 && $crossMappings > 0 && $auditsDone >= 1) {
                $level = self::LEVEL_DEFINED;
            } else {
                if ($coverage < 80) {
                    $missing[] = sprintf('coverage < 80%% (aktuell %d %%)', $coverage);
                }
                if ($crossMappings === 0) {
                    $missing[] = 'keine Cross-Framework-Mappings auf oder aus diesem Framework';
                }
                if ($auditsDone === 0) {
                    $missing[] = 'noch kein interner Audit erfasst';
                }
            }
        }

        if ($level >= self::LEVEL_DEFINED) {
            if ($snapshotMonths >= 6) {
                $level = self::LEVEL_QUANTITATIVELY_MANAGED;
            } else {
                $missing[] = sprintf('KPI-Snapshot-Historie < 6 Monate (aktuell %d)', $snapshotMonths);
            }
        }

        if ($level >= self::LEVEL_QUANTITATIVELY_MANAGED) {
            if ($auditsDone >= 2) {
                $level = self::LEVEL_OPTIMIZING;
            } else {
                $missing[] = 'Level 5 braucht ≥ 2 Audits mit dokumentierter Verbesserung';
            }
        }

        return [
            'level' => $level,
            'label' => self::LEVEL_LABEL[$level] ?? 'unknown',
            'coverage_pct' => $coverage,
            'cross_mappings' => $crossMappings,
            'audits' => $auditsDone,
            'snapshot_months' => $snapshotMonths,
            'missing' => $missing,
        ];
    }

    /**
     * Portfolio-Ansicht: Reifegrad pro aktives Framework.
     *
     * @return array<string, array>
     */
    public function computePortfolio(?Tenant $tenant = null): array
    {
        $out = [];
        foreach ($this->frameworkRepository->findBy(['active' => true]) as $fw) {
            $code = (string) $fw->getCode();
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'framework' => $fw,
                'maturity' => $this->computeForFramework($fw, $tenant),
            ];
        }
        return $out;
    }

    /** @param list<\App\Entity\ComplianceRequirement> $requirements */
    private function averageCoverage(array $requirements): int
    {
        if ($requirements === []) {
            return 0;
        }
        $sum = 0;
        foreach ($requirements as $r) {
            $sum += $r->calculateFulfillmentFromControls();
        }
        return (int) round($sum / count($requirements));
    }

    private function countAudits(?Tenant $tenant): int
    {
        if ($this->auditRepository === null) {
            return 0;
        }
        if ($tenant !== null) {
            return count($this->auditRepository->findBy(['tenant' => $tenant]));
        }
        return count($this->auditRepository->findAll());
    }

    /**
     * Approximate KPI-snapshot history in months by looking at the
     * spread between earliest and latest snapshot. Any KpiSnapshotRepository
     * contract works as long as it exposes findOneBy() and createQueryBuilder().
     */
    private function snapshotHistoryMonths(): int
    {
        if ($this->kpiSnapshotRepository === null) {
            return 0;
        }
        try {
            $qb = $this->kpiSnapshotRepository->createQueryBuilder('s')
                ->select('MIN(s.snapshotDate) AS firstSnap, MAX(s.snapshotDate) AS lastSnap');
            $row = $qb->getQuery()->getOneOrNullResult();
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($row) || empty($row['firstSnap']) || empty($row['lastSnap'])) {
            return 0;
        }
        $first = new \DateTimeImmutable((string) $row['firstSnap']);
        $last = new \DateTimeImmutable((string) $row['lastSnap']);
        $diff = $first->diff($last);
        return ($diff->y * 12) + $diff->m;
    }

    private function emptyResult(): array
    {
        return [
            'level' => self::LEVEL_NA,
            'label' => self::LEVEL_LABEL[self::LEVEL_NA],
            'coverage_pct' => 0,
            'cross_mappings' => 0,
            'audits' => 0,
            'snapshot_months' => 0,
            'missing' => ['Framework ohne erfasste Anforderungen'],
        ];
    }
}
