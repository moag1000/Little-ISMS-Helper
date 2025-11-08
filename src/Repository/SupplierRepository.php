<?php

namespace App\Repository;

use App\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplier>
 */
class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    /**
     * Find suppliers with overdue assessments
     */
    public function findOverdueAssessments(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.nextAssessmentDate < :now')
            ->orWhere('s.lastSecurityAssessment IS NULL AND s.status = :active')
            ->setParameter('now', new \DateTime())
            ->setParameter('active', 'active')
            ->orderBy('s.criticality', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical suppliers
     */
    public function findCriticalSuppliers(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.criticality IN (:criticalities)')
            ->andWhere('s.status = :active')
            ->setParameter('criticalities', ['critical', 'high'])
            ->setParameter('active', 'active')
            ->orderBy('s.criticality', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find suppliers without required certifications
     */
    public function findNonCompliant(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.hasISO27001 = :false OR s.hasDPA = :false')
            ->andWhere('s.status = :active')
            ->andWhere('s.criticality IN (:criticalities)')
            ->setParameter('false', false)
            ->setParameter('active', 'active')
            ->setParameter('criticalities', ['critical', 'high'])
            ->getQuery()
            ->getResult();
    }

    /**
     * Get supplier statistics
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('s');

        $total = $qb->select('COUNT(s.id)')
            ->where('s.status = :active')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $critical = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.criticality = :critical')
            ->andWhere('s.status = :active')
            ->setParameter('critical', 'critical')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $iso27001 = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.hasISO27001 = :true')
            ->andWhere('s.status = :active')
            ->setParameter('true', true)
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $overdue = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.nextAssessmentDate < :now OR (s.lastSecurityAssessment IS NULL AND s.status = :active)')
            ->setParameter('now', new \DateTime())
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'critical' => $critical,
            'iso27001_certified' => $iso27001,
            'assessments_overdue' => $overdue,
            'compliance_rate' => $total > 0 ? round(($iso27001 / $total) * 100, 2) : 0
        ];
    }
}
