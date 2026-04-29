<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdentityProvider;
use App\Entity\SsoUserApproval;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SsoUserApproval>
 */
class SsoUserApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SsoUserApproval::class);
    }

    /** @return list<SsoUserApproval> */
    public function findPendingForTenant(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.status = :p')
            ->setParameter('p', SsoUserApproval::STATUS_PENDING);
        if ($tenant === null) {
            $qb->andWhere('a.tenant IS NULL');
        } else {
            $qb->andWhere('a.tenant IS NULL OR a.tenant = :t')
               ->setParameter('t', $tenant);
        }
        $qb->orderBy('a.requestedAt', 'DESC');

        return array_values($qb->getQuery()->getResult());
    }

    public function findPendingForProviderEmail(IdentityProvider $provider, string $email): ?SsoUserApproval
    {
        return $this->findOneBy([
            'provider' => $provider,
            'email' => $email,
            'status' => SsoUserApproval::STATUS_PENDING,
        ]);
    }
}
