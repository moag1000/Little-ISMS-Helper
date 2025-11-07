<?php

namespace App\Repository;

use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Compliance Framework Repository
 *
 * Repository for querying ComplianceFramework entities with industry and status filtering.
 *
 * @extends ServiceEntityRepository<ComplianceFramework>
 *
 * @method ComplianceFramework|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceFramework|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComplianceFramework[]    findAll()
 * @method ComplianceFramework[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceFrameworkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceFramework::class);
    }

    /**
     * Find all active compliance frameworks.
     *
     * @return ComplianceFramework[] Array of active frameworks sorted by name
     */
    public function findActiveFrameworks(): array
    {
        return $this->createQueryBuilder('cf')
            ->where('cf.active = :active')
            ->setParameter('active', true)
            ->orderBy('cf.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find mandatory compliance frameworks (required by regulation or policy).
     *
     * @return ComplianceFramework[] Array of mandatory active frameworks sorted by name
     */
    public function findMandatoryFrameworks(): array
    {
        return $this->createQueryBuilder('cf')
            ->where('cf.mandatory = :mandatory')
            ->andWhere('cf.active = :active')
            ->setParameter('mandatory', true)
            ->setParameter('active', true)
            ->orderBy('cf.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find frameworks applicable to a specific industry.
     *
     * @param string $industry Industry identifier (e.g., 'healthcare', 'finance', 'manufacturing')
     * @return ComplianceFramework[] Array of applicable active frameworks sorted by name
     */
    public function findByIndustry(string $industry): array
    {
        return $this->createQueryBuilder('cf')
            ->where('cf.applicableIndustry = :industry OR cf.applicableIndustry = :all')
            ->andWhere('cf.active = :active')
            ->setParameter('industry', $industry)
            ->setParameter('all', 'all')
            ->setParameter('active', true)
            ->orderBy('cf.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get compliance overview with statistics for all active frameworks.
     *
     * @return array<array{id: int, code: string, name: string, mandatory: bool, total_requirements: int, applicable_requirements: int, fulfilled_requirements: int, compliance_percentage: float}>
     */
    public function getComplianceOverview(): array
    {
        $frameworks = $this->findActiveFrameworks();
        $overview = [];

        foreach ($frameworks as $framework) {
            $overview[] = [
                'id' => $framework->getId(),
                'code' => $framework->getCode(),
                'name' => $framework->getName(),
                'mandatory' => $framework->isMandatory(),
                'total_requirements' => $framework->getRequirements()->count(),
                'applicable_requirements' => $framework->getApplicableRequirementsCount(),
                'fulfilled_requirements' => $framework->getFulfilledRequirementsCount(),
                'compliance_percentage' => $framework->getCompliancePercentage(),
            ];
        }

        return $overview;
    }
}
