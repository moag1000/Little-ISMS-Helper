<?php

namespace App\Repository;

use App\Entity\RiskAppetite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Risk Appetite Repository
 *
 * Repository for querying Risk Appetite entities for ISO 27005 compliance.
 *
 * Features:
 * - Find active and valid risk appetites
 * - Check validity periods
 * - Review tracking
 *
 * @extends ServiceEntityRepository<RiskAppetite>
 *
 * @method RiskAppetite|null find($id, $lockMode = null, $lockVersion = null)
 * @method RiskAppetite|null findOneBy(array $criteria, array $orderBy = null)
 * @method RiskAppetite[]    findAll()
 * @method RiskAppetite[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RiskAppetiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskAppetite::class);
    }

    /**
     * Find the currently active risk appetite
     *
     * @return RiskAppetite|null
     */
    public function findActive(): ?RiskAppetite
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('ra')
            ->where('ra.isActive = :active')
            ->andWhere('ra.validFrom <= :now')
            ->andWhere('ra.validTo IS NULL OR ra.validTo >= :now')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('ra.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active risk appetites
     *
     * @return RiskAppetite[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('ra.validFrom', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risk appetites with overdue reviews
     *
     * @return RiskAppetite[]
     */
    public function findWithOverdueReview(): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('ra')
            ->where('ra.isActive = :active')
            ->andWhere('ra.reviewDate IS NOT NULL')
            ->andWhere('ra.reviewDate < :now')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('ra.reviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risk appetites expiring soon (within next N days)
     *
     * @return RiskAppetite[]
     */
    public function findExpiringSoon(int $days = 30): array
    {
        $now = new \DateTimeImmutable();
        $future = $now->modify("+{$days} days");

        return $this->createQueryBuilder('ra')
            ->where('ra.isActive = :active')
            ->andWhere('ra.validTo IS NOT NULL')
            ->andWhere('ra.validTo BETWEEN :now AND :future')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->setParameter('future', $future)
            ->orderBy('ra.validTo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risk appetites by appetite level
     *
     * @return RiskAppetite[]
     */
    public function findByAppetiteLevel(string $appetiteLevel): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.isActive = :active')
            ->andWhere('ra.appetiteLevel = :level')
            ->setParameter('active', true)
            ->setParameter('level', $appetiteLevel)
            ->orderBy('ra.validFrom', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unapproved risk appetites
     *
     * @return RiskAppetite[]
     */
    public function findUnapproved(): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.approvedBy IS NULL OR ra.approvedAt IS NULL')
            ->andWhere('ra.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('ra.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
