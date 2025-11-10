<?php

namespace App\Repository;

use App\Entity\CrisisTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Crisis Team Repository
 *
 * Repository for querying Crisis Team entities for BSI IT-Grundschutz compliance.
 *
 * Features:
 * - Find active crisis teams
 * - Find teams by type
 * - Training compliance tracking
 *
 * @extends ServiceEntityRepository<CrisisTeam>
 *
 * @method CrisisTeam|null find($id, $lockMode = null, $lockVersion = null)
 * @method CrisisTeam|null findOneBy(array $criteria, array $orderBy = null)
 * @method CrisisTeam[]    findAll()
 * @method CrisisTeam[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CrisisTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrisisTeam::class);
    }

    /**
     * Find all active crisis teams
     *
     * @return CrisisTeam[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('ct')
            ->where('ct.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('ct.teamName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find teams by type
     *
     * @return CrisisTeam[]
     */
    public function findByType(string $teamType): array
    {
        return $this->createQueryBuilder('ct')
            ->where('ct.teamType = :type')
            ->andWhere('ct.isActive = :active')
            ->setParameter('type', $teamType)
            ->setParameter('active', true)
            ->orderBy('ct.teamName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find teams with overdue training
     *
     * @return CrisisTeam[]
     */
    public function findWithOverdueTraining(): array
    {
        return $this->createQueryBuilder('ct')
            ->where('ct.nextTrainingAt IS NOT NULL')
            ->andWhere('ct.nextTrainingAt < :now')
            ->andWhere('ct.isActive = :active')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('active', true)
            ->orderBy('ct.nextTrainingAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find teams without proper configuration
     *
     * @return CrisisTeam[]
     */
    public function findIncompleteTeams(): array
    {
        return $this->createQueryBuilder('ct')
            ->where('ct.teamLeader IS NULL OR ct.primaryPhone IS NULL OR ct.primaryEmail IS NULL')
            ->andWhere('ct.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('ct.teamName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
