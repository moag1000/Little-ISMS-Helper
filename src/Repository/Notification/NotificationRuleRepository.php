<?php

declare(strict_types=1);

namespace App\Repository\Notification;

use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationRule>
 *
 * @method NotificationRule|null find($id, $lockMode = null, $lockVersion = null)
 * @method NotificationRule|null findOneBy(array $criteria, array $orderBy = null)
 * @method NotificationRule[]    findAll()
 * @method NotificationRule[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationRule::class);
    }

    /**
     * Find active rules for a given event type scoped to a tenant.
     *
     * @return NotificationRule[]
     */
    public function findActiveByEventType(string $eventType, Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.eventType = :eventType')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.isActive = true')
            ->setParameter('eventType', $eventType)
            ->setParameter('tenant', $tenant)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
