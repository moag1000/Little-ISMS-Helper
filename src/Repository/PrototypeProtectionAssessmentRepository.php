<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrototypeProtectionAssessment;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrototypeProtectionAssessment>
 *
 * @method PrototypeProtectionAssessment|null find($id, $lockMode = null, $lockVersion = null)
 * @method PrototypeProtectionAssessment|null findOneBy(array $criteria, array $orderBy = null)
 * @method PrototypeProtectionAssessment[]    findAll()
 * @method PrototypeProtectionAssessment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PrototypeProtectionAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrototypeProtectionAssessment::class);
    }

    /**
     * @return PrototypeProtectionAssessment[]
     */
    public function findForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :t')
            ->setParameter('t', $tenant)
            ->orderBy('a.assessmentDate', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Return assessments that will expire within the next N days and
     * whose status is still 'approved'. Drives dashboard reminders.
     *
     * @return PrototypeProtectionAssessment[]
     */
    public function findExpiringSoon(Tenant $tenant, int $daysAhead = 60): array
    {
        $cutoff = (new \DateTimeImmutable())->modify('+' . $daysAhead . ' days');
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :t')
            ->andWhere('a.status = :s')
            ->andWhere('a.nextAssessmentDue IS NOT NULL')
            ->andWhere('a.nextAssessmentDue <= :cutoff')
            ->setParameter('t', $tenant)
            ->setParameter('s', PrototypeProtectionAssessment::STATUS_APPROVED)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('a.nextAssessmentDue', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
