<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\UnavailabilityCalendar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnavailabilityCalendar>
 */
class UnavailabilityCalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnavailabilityCalendar::class);
    }

    public function findForTenant(Tenant $tenant): ?UnavailabilityCalendar
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }
}
