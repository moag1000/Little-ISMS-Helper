<?php

declare(strict_types=1);

namespace App\Repository\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationChannel>
 *
 * @method NotificationChannel|null find($id, $lockMode = null, $lockVersion = null)
 * @method NotificationChannel|null findOneBy(array $criteria, array $orderBy = null)
 * @method NotificationChannel[]    findAll()
 * @method NotificationChannel[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationChannel::class);
    }

    /**
     * Find active channels of a specific type for a tenant.
     *
     * @return NotificationChannel[]
     */
    public function findActiveByType(string $type, Tenant $tenant): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.type = :type')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.isActive = true')
            ->setParameter('type', $type)
            ->setParameter('tenant', $tenant)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
