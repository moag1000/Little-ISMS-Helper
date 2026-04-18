<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRowEvent>
 *
 * @method ImportRowEvent|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportRowEvent|null findOneBy(array $criteria, array $orderBy = null)
 * @method ImportRowEvent[]    findAll()
 * @method ImportRowEvent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportRowEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRowEvent::class);
    }

    /**
     * Answer the ISB audit question: "show me how $entityType #$entityId got
     * created / changed during imports". Returns newest rows first.
     *
     * @return ImportRowEvent[]
     */
    public function findByTarget(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.targetEntityType = :type')
            ->andWhere('e.targetEntityId = :id')
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count row events for a given session filtered by decision.
     */
    public function countBySession(ImportSession $session, string $decision): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.session = :session')
            ->andWhere('e.decision = :decision')
            ->setParameter('session', $session)
            ->setParameter('decision', $decision)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Paginate row events for a session, optionally filtered by decision
     * and/or target entity type.
     *
     * @return ImportRowEvent[]
     */
    public function findBySessionPaginated(
        ImportSession $session,
        int $limit,
        int $offset,
        ?string $decision = null,
        ?string $targetEntityType = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.session = :session')
            ->setParameter('session', $session);

        if ($decision !== null && $decision !== '') {
            $qb->andWhere('e.decision = :decision')
                ->setParameter('decision', $decision);
        }

        if ($targetEntityType !== null && $targetEntityType !== '') {
            $qb->andWhere('e.targetEntityType = :type')
                ->setParameter('type', $targetEntityType);
        }

        return $qb->orderBy('e.lineNumber', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countBySessionFiltered(
        ImportSession $session,
        ?string $decision = null,
        ?string $targetEntityType = null,
    ): int {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.session = :session')
            ->setParameter('session', $session);

        if ($decision !== null && $decision !== '') {
            $qb->andWhere('e.decision = :decision')
                ->setParameter('decision', $decision);
        }

        if ($targetEntityType !== null && $targetEntityType !== '') {
            $qb->andWhere('e.targetEntityType = :type')
                ->setParameter('type', $targetEntityType);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
