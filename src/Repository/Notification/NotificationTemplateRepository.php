<?php

declare(strict_types=1);

namespace App\Repository\Notification;

use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationTemplate>
 *
 * @method NotificationTemplate|null find($id, $lockMode = null, $lockVersion = null)
 * @method NotificationTemplate|null findOneBy(array $criteria, array $orderBy = null)
 * @method NotificationTemplate[]    findAll()
 * @method NotificationTemplate[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationTemplate::class);
    }

    /**
     * Find templates available for a given tenant: global (tenant_id IS NULL) plus
     * any tenant-specific templates. If $tenant is null, returns only global templates.
     *
     * @return NotificationTemplate[]
     */
    public function findAvailableForTenant(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.tenant IS NULL');

        if ($tenant !== null) {
            $qb->orWhere('t.tenant = :tenant')
               ->setParameter('tenant', $tenant);
        }

        return $qb
            ->orderBy('t.category', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
