<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationSecurityProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationSecurityProfile>
 */
class OrganizationSecurityProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationSecurityProfile::class);
    }

    public function findForTenant(int $tenantId): ?OrganizationSecurityProfile
    {
        return $this->findOneBy(['tenantId' => $tenantId]);
    }

    public function save(OrganizationSecurityProfile $profile): void
    {
        $em = $this->getEntityManager();
        $em->persist($profile);
        $em->flush();
    }
}
