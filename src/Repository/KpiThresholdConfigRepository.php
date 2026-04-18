<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KpiThresholdConfig;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KpiThresholdConfig>
 */
class KpiThresholdConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KpiThresholdConfig::class);
    }

    /**
     * @return array<string, array{good:int, warning:int}> Map of kpi_key => thresholds for the tenant.
     */
    public function getThresholdMap(?Tenant $tenant): array
    {
        if (!$tenant instanceof Tenant) {
            return [];
        }
        $rows = $this->findBy(['tenant' => $tenant]);
        $map = [];
        foreach ($rows as $row) {
            $key = $row->getKpiKey();
            if ($key === null) {
                continue;
            }
            $map[$key] = [
                'good' => (int) $row->getGoodThreshold(),
                'warning' => (int) $row->getWarningThreshold(),
            ];
        }
        return $map;
    }
}
