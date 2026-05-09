<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PolicyTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PolicyTemplate>
 *
 * @method PolicyTemplate|null find($id, $lockMode = null, $lockVersion = null)
 * @method PolicyTemplate|null findOneBy(array $criteria, array $orderBy = null)
 * @method PolicyTemplate[]    findAll()
 * @method PolicyTemplate[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PolicyTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PolicyTemplate::class);
    }

    /**
     * Active templates for a given standard, ordered by topic.
     *
     * @return PolicyTemplate[]
     */
    public function findActiveByStandard(string $standard): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.standard = :standard')
            ->andWhere('t.isActive = :active')
            ->setParameter('standard', $standard)
            ->setParameter('active', true)
            ->orderBy('t.topic', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByKey(string $key): ?PolicyTemplate
    {
        return $this->findOneBy(['key' => $key]);
    }
}
