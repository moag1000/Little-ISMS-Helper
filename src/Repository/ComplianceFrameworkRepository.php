<?php

namespace App\Repository;

use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceFramework>
 */
class ComplianceFrameworkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceFramework::class);
    }

    /**
     * Find all active frameworks
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
     * Find mandatory frameworks
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
     * Find frameworks by industry
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
     * Get compliance overview statistics
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
