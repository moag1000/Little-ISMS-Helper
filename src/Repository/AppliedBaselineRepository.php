<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppliedBaseline;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppliedBaseline>
 */
class AppliedBaselineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppliedBaseline::class);
    }

    /**
     * @return AppliedBaseline[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['appliedAt' => 'DESC']);
    }

    public function findOneByTenantAndCode(Tenant $tenant, string $code): ?AppliedBaseline
    {
        return $this->findOneBy(['tenant' => $tenant, 'baselineCode' => $code]);
    }

    /**
     * Baselines applied to any ancestor of $tenant but not directly to
     * the tenant itself. Keyed by baseline code so a child can display
     * "inherited from Holding X" without loading the full ancestor chain
     * repeatedly. Phase 9.P1.4 read-only inheritance view.
     *
     * If several ancestors applied the same baseline, the immediate
     * parent's record wins (closest origin).
     *
     * @return array<string, AppliedBaseline>
     */
    public function findInheritedByTenant(Tenant $tenant): array
    {
        $ownCodes = array_map(
            static fn(AppliedBaseline $a): string => $a->getBaselineCode(),
            $this->findByTenant($tenant)
        );

        $inherited = [];
        foreach ($tenant->getAllAncestors() as $ancestor) {
            foreach ($this->findByTenant($ancestor) as $applied) {
                $code = $applied->getBaselineCode();
                if (in_array($code, $ownCodes, true)) {
                    continue;
                }
                if (!isset($inherited[$code])) {
                    $inherited[$code] = $applied;
                }
            }
        }
        return $inherited;
    }
}
