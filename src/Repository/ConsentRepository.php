<?php

namespace App\Repository;

use App\Entity\Consent;
use App\Entity\Tenant;
use App\Entity\ProcessingActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Consent>
 */
class ConsentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Consent::class);
    }

    /**
     * Find active consents for a processing activity
     *
     * @return Consent[]
     */
    public function findActiveByProcessingActivity(ProcessingActivity $processingActivity): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.processingActivity = :activity')
            ->andWhere('c.status = :status')
            ->andWhere('c.isRevoked = :revoked')
            ->setParameter('activity', $processingActivity)
            ->setParameter('status', 'active')
            ->setParameter('revoked', false)
            ->orderBy('c.grantedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find consents pending DPO verification
     *
     * @return Consent[]
     */
    public function findPendingVerification(?Tenant $tenant = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.isVerifiedByDpo = :verified')
            ->setParameter('status', 'pending_verification')
            ->setParameter('verified', false)
            ->orderBy('c.documentedAt', 'ASC');

        if ($tenant !== null) {
            $qb->andWhere('c.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find consents expiring within specified days
     *
     * @return Consent[]
     */
    public function findExpiringSoon(int $daysAhead = 30, ?Tenant $tenant = null): array
    {
        $now = new \DateTimeImmutable();
        $futureDate = $now->modify(sprintf('+%d days', $daysAhead));

        $qb = $this->createQueryBuilder('c')
            ->where('c.expiresAt IS NOT NULL')
            ->andWhere('c.expiresAt BETWEEN :now AND :future')
            ->andWhere('c.status = :status')
            ->andWhere('c.isRevoked = :revoked')
            ->setParameter('now', $now)
            ->setParameter('future', $futureDate)
            ->setParameter('status', 'active')
            ->setParameter('revoked', false)
            ->orderBy('c.expiresAt', 'ASC');

        if ($tenant !== null) {
            $qb->andWhere('c.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find consents by data subject identifier
     *
     * @return Consent[]
     */
    public function findByDataSubject(string $identifier, ?Tenant $tenant = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.dataSubjectIdentifier = :identifier')
            ->setParameter('identifier', $identifier)
            ->orderBy('c.grantedAt', 'DESC');

        if ($tenant !== null) {
            $qb->andWhere('c.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get consent statistics for dashboard
     */
    public function getStatistics(?Tenant $tenant = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('
                COUNT(c.id) as total,
                SUM(CASE WHEN c.status = :active AND c.isVerifiedByDpo = :verified THEN 1 ELSE 0 END) as active_verified,
                SUM(CASE WHEN c.status = :pending THEN 1 ELSE 0 END) as pending_verification,
                SUM(CASE WHEN c.isRevoked = :revoked_true THEN 1 ELSE 0 END) as revoked_total,
                SUM(CASE WHEN c.status = :expired THEN 1 ELSE 0 END) as expired
            ')
            ->setParameter('active', 'active')
            ->setParameter('pending', 'pending_verification')
            ->setParameter('verified', true)
            ->setParameter('revoked_true', true)
            ->setParameter('expired', 'expired');

        if ($tenant !== null) {
            $qb->where('c.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        $result = $qb->getQuery()->getSingleResult();

        // Calculate revocation rate for last 30 days
        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');
        $recentRevokedQb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.isRevoked = :revoked')
            ->andWhere('c.revokedAt >= :thirtyDaysAgo')
            ->setParameter('revoked', true)
            ->setParameter('thirtyDaysAgo', $thirtyDaysAgo);

        if ($tenant !== null) {
            $recentRevokedQb->andWhere('c.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        $result['revoked_last_30_days'] = $recentRevokedQb->getQuery()->getSingleScalarResult();

        // Calculate revocation rates
        $total = (int) $result['total'];
        $result['revocation_rate_total'] = $total > 0
            ? round(((int) $result['revoked_total'] / $total) * 100, 2)
            : 0;

        $result['revocation_rate_last_30_days'] = $total > 0
            ? round(((int) $result['revoked_last_30_days'] / $total) * 100, 2)
            : 0;

        return $result;
    }

    /**
     * Count active consents for a processing activity
     */
    public function countActiveForProcessingActivity(ProcessingActivity $processingActivity): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.processingActivity = :activity')
            ->andWhere('c.status = :status')
            ->andWhere('c.isRevoked = :revoked')
            ->andWhere('c.isVerifiedByDpo = :verified')
            ->setParameter('activity', $processingActivity)
            ->setParameter('status', 'active')
            ->setParameter('revoked', false)
            ->setParameter('verified', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
