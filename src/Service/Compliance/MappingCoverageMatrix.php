<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Framework-pair coverage and confidence distribution for the mapping-quality
 * coverage page. Three grouped queries for all pairs instead of four per
 * pair; counts only mappings and requirements visible to the current tenant.
 */
final class MappingCoverageMatrix
{
    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MappingAccessGuard $accessGuard,
    ) {
    }

    /**
     * @param list<ComplianceFramework> $frameworks
     * @return list<array{source: ComplianceFramework, target: ComplianceFramework, source_total: int,
     *     covered: int, unmapped: int, coverage_pct: float, high: int, medium: int, low: int}>
     */
    public function rows(array $frameworks): array
    {
        $byId = [];
        foreach ($frameworks as $framework) {
            $byId[$framework->getId()] = $framework;
        }
        $totals = $this->requirementTotals();

        $base = fn() => $this->accessGuard->scopeQuery(
            $this->mappingRepository->createQueryBuilder('cm')
                ->join('cm.sourceRequirement', 'sr')
                ->join('cm.targetRequirement', 'tr')
                ->andWhere("cm.lifecycleState != 'deprecated'")
                ->andWhere('IDENTITY(sr.framework) != IDENTITY(tr.framework)'),
        );

        $covered = [];
        foreach ($base()->select('IDENTITY(sr.framework) AS sf, IDENTITY(tr.framework) AS tf, COUNT(DISTINCT sr.id) AS n')
            ->groupBy('sf, tf')->getQuery()->getArrayResult() as $r) {
            $covered[(int) $r['sf']][(int) $r['tf']] = (int) $r['n'];
        }
        $confidence = [];
        foreach ($base()->select('IDENTITY(sr.framework) AS sf, IDENTITY(tr.framework) AS tf, cm.confidence AS c, COUNT(cm.id) AS n')
            ->groupBy('sf, tf, c')->getQuery()->getArrayResult() as $r) {
            $confidence[(int) $r['sf']][(int) $r['tf']][(string) $r['c']] = (int) $r['n'];
        }

        $rows = [];
        foreach ($covered as $sf => $targets) {
            foreach ($targets as $tf => $count) {
                if (!isset($byId[$sf], $byId[$tf]) || $count === 0) {
                    continue;
                }
                $total = $totals[$sf] ?? 0;
                $rows[] = [
                    'source' => $byId[$sf],
                    'target' => $byId[$tf],
                    'source_total' => $total,
                    'covered' => $count,
                    'unmapped' => $total - $count,
                    'coverage_pct' => round($total > 0 ? ($count / $total) * 100 : 0, 1),
                    'high' => $confidence[$sf][$tf]['high'] ?? 0,
                    'medium' => $confidence[$sf][$tf]['medium'] ?? 0,
                    'low' => $confidence[$sf][$tf]['low'] ?? 0,
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $b['coverage_pct'] <=> $a['coverage_pct']);

        return $rows;
    }

    /** @return array<int, int> requirement count per framework id (global + own uploads) */
    private function requirementTotals(): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(r.framework) AS fw, COUNT(r.id) AS n')
            ->from(ComplianceRequirement::class, 'r')
            ->groupBy('fw');
        $tenant = $this->accessGuard->currentTenant();
        if ($tenant !== null) {
            $qb->where('r.uploadTenant IS NULL OR r.uploadTenant = :tenant')->setParameter('tenant', $tenant);
        } elseif (!$this->accessGuard->canSeeAll()) {
            $qb->where('1 = 0');
        }

        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $r) {
            $out[(int) $r['fw']] = (int) $r['n'];
        }

        return $out;
    }
}
