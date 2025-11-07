<?php

namespace App\Repository;

use App\Entity\Training;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Training Repository
 *
 * Repository for querying Training entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Training>
 *
 * @method Training|null find($id, $lockMode = null, $lockVersion = null)
 * @method Training|null findOneBy(array $criteria, array $orderBy = null)
 * @method Training[]    findAll()
 * @method Training[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TrainingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Training::class);
    }

    /**
     * Find upcoming trainings (planned or scheduled with future dates).
     *
     * @return Training[] Array of Training entities sorted by scheduled date
     */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->andWhere('t.scheduledDate >= :today')
            ->setParameter('statuses', ['planned', 'scheduled'])
            ->setParameter('today', new \DateTime())
            ->orderBy('t.scheduledDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
