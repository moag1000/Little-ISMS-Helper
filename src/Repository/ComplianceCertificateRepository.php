<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceCertificate;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceCertificate>
 *
 * @method ComplianceCertificate|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceCertificate|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComplianceCertificate[]    findAll()
 * @method ComplianceCertificate[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceCertificateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceCertificate::class);
    }

    /**
     * Return all active (non-expired) certificates for a tenant scoped to a
     * specific framework code.
     *
     * @return ComplianceCertificate[]
     */
    public function findActiveByTenantAndFramework(Tenant $tenant, string $frameworkCode): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->andWhere('c.frameworkCode = :frameworkCode')
            ->andWhere('c.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('frameworkCode', $frameworkCode)
            ->setParameter('status', 'active')
            ->orderBy('c.validUntil', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Return all certificates (any status) for a tenant.
     *
     * @return ComplianceCertificate[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['createdAt' => 'DESC']);
    }

    /**
     * Return active certificates of a tenant whose validity has lapsed
     * (status='active' AND validUntil < now AND validUntil IS NOT NULL).
     *
     * Drives the cron-side status flip from 'active' to 'expired' so expired
     * certificates stop being treated as active by
     * {@see findActiveByTenantAndFramework()}.
     *
     * @return ComplianceCertificate[]
     */
    public function findExpiredActive(Tenant $tenant, DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->andWhere('c.status = :status')
            ->andWhere('c.validUntil IS NOT NULL')
            ->andWhere('c.validUntil < :now')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
