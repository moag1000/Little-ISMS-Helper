<?php

declare(strict_types=1);

namespace App\Repository\Notification;

use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationDelivery>
 *
 * @method NotificationDelivery|null find($id, $lockMode = null, $lockVersion = null)
 * @method NotificationDelivery|null findOneBy(array $criteria, array $orderBy = null)
 * @method NotificationDelivery[]    findAll()
 * @method NotificationDelivery[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDelivery::class);
    }

    /**
     * Find the most recent deliveries for a given rule, newest first.
     *
     * @return NotificationDelivery[]
     */
    public function findRecentByRule(NotificationRule $rule, int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.rule = :rule')
            ->setParameter('rule', $rule)
            ->orderBy('d.attemptedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all failed deliveries since a given point in time for a tenant.
     *
     * @return NotificationDelivery[]
     */
    public function findFailedSince(DateTimeImmutable $since, Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.attemptedAt >= :since')
            ->setParameter('status', NotificationDelivery::STATUS_FAILED)
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->orderBy('d.attemptedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
