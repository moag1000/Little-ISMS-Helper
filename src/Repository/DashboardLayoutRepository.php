<?php

namespace App\Repository;

use App\Entity\DashboardLayout;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DashboardLayout>
 */
class DashboardLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DashboardLayout::class);
    }

    /**
     * Find layout for specific user and tenant
     */
    public function findForUser(User $user, Tenant $tenant): ?DashboardLayout
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find or create layout for user
     */
    public function findOrCreateForUser(User $user, Tenant $tenant): DashboardLayout
    {
        $layout = $this->findForUser($user, $tenant);

        if (!$layout) {
            $layout = new DashboardLayout();
            $layout->setUser($user);
            $layout->setTenant($tenant);

            $this->getEntityManager()->persist($layout);
            $this->getEntityManager()->flush();
        }

        return $layout;
    }

    /**
     * Save layout configuration
     */
    public function saveLayout(DashboardLayout $layout): void
    {
        $this->getEntityManager()->persist($layout);
        $this->getEntityManager()->flush();
    }

    /**
     * Reset layout to defaults
     */
    public function resetToDefaults(User $user, Tenant $tenant): DashboardLayout
    {
        $layout = $this->findForUser($user, $tenant);

        if ($layout) {
            $this->getEntityManager()->remove($layout);
            $this->getEntityManager()->flush();
        }

        return $this->findOrCreateForUser($user, $tenant);
    }
}
