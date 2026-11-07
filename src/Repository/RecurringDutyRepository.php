<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecurringDuty;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RecurringDuty> */
class RecurringDutyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringDuty::class);
    }

    /** @return array<string, RecurringDuty> keyed by duty key */
    public function findByTenantKeyed(Tenant $tenant): array
    {
        $rows = [];
        foreach ($this->findBy(['tenant' => $tenant]) as $row) {
            $rows[$row->getDutyKey()] = $row;
        }

        return $rows;
    }
}
