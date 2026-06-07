<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSettings;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSettings>
 */
class PlanningSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSettings::class);
    }

    public function findForTenant(Tenant $tenant): ?PlanningSettings
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /** Get existing settings or a transient default instance (not persisted). */
    public function getOrCreate(Tenant $tenant): PlanningSettings
    {
        $settings = $this->findForTenant($tenant);
        if ($settings === null) {
            $settings = new PlanningSettings();
            $settings->setTenant($tenant);
        }
        return $settings;
    }
}
