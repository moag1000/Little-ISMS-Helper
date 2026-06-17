<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CertificateCoverageRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CertificateCoverageRule>
 *
 * @method CertificateCoverageRule|null find($id, $lockMode = null, $lockVersion = null)
 * @method CertificateCoverageRule|null findOneBy(array $criteria, array $orderBy = null)
 * @method CertificateCoverageRule[]    findAll()
 * @method CertificateCoverageRule[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CertificateCoverageRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CertificateCoverageRule::class);
    }

    /**
     * Return all active coverage rules for the given framework code.
     *
     * @return CertificateCoverageRule[]
     */
    public function findActiveByFramework(string $code): array
    {
        return $this->findBy(['frameworkCode' => $code, 'active' => true]);
    }
}
