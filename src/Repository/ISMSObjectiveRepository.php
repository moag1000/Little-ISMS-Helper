<?php

namespace App\Repository;

use App\Entity\ISMSObjective;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ISMS Objective Repository
 *
 * Repository for querying ISMSObjective entities (ISO 27001 Clause 6.2).
 *
 * @extends ServiceEntityRepository<ISMSObjective>
 *
 * @method ISMSObjective|null find($id, $lockMode = null, $lockVersion = null)
 * @method ISMSObjective|null findOneBy(array $criteria, array $orderBy = null)
 * @method ISMSObjective[]    findAll()
 * @method ISMSObjective[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ISMSObjectiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ISMSObjective::class);
    }

    /**
     * Find active ISMS objectives (in progress or not yet started).
     *
     * @return ISMSObjective[] Array of active objectives sorted by target date
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', ['in_progress', 'not_started'])
            ->orderBy('o.targetDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
