<?php

namespace App\Repository;

use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporateGovernance>
 */
class CorporateGovernanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporateGovernance::class);
    }

    /**
     * Find governance for a specific scope with fallback to defaults
     * Priority: specific scopeId > scope default > global default
     */
    public function findGovernanceForScope(
        Tenant $tenant,
        string $scope,
        ?string $scopeId = null
    ): ?CorporateGovernance {
        // Try specific scope ID first
        if ($scopeId !== null) {
            $specific = $this->createQueryBuilder('cg')
                ->where('cg.tenant = :tenant')
                ->andWhere('cg.scope = :scope')
                ->andWhere('cg.scopeId = :scopeId')
                ->setParameter('tenant', $tenant)
                ->setParameter('scope', $scope)
                ->setParameter('scopeId', $scopeId)
                ->getQuery()
                ->getOneOrNullResult();

            if ($specific) {
                return $specific;
            }
        }

        // Fall back to scope default (scopeId = NULL)
        $scopeDefault = $this->createQueryBuilder('cg')
            ->where('cg.tenant = :tenant')
            ->andWhere('cg.scope = :scope')
            ->andWhere('cg.scopeId IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('scope', $scope)
            ->getQuery()
            ->getOneOrNullResult();

        if ($scopeDefault) {
            return $scopeDefault;
        }

        // Fall back to global default
        return $this->findDefaultGovernance($tenant);
    }

    /**
     * Get default governance for a tenant (scope = 'default')
     */
    public function findDefaultGovernance(Tenant $tenant): ?CorporateGovernance
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'scope' => 'default',
            'scopeId' => null,
        ]);
    }

    /**
     * Find all governance rules for a tenant
     */
    public function findAllForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('cg')
            ->where('cg.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('cg.scope', 'ASC')
            ->addOrderBy('cg.scopeId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find governance rules by scope type
     */
    public function findByScope(Tenant $tenant, string $scope): array
    {
        return $this->createQueryBuilder('cg')
            ->where('cg.tenant = :tenant')
            ->andWhere('cg.scope = :scope')
            ->setParameter('tenant', $tenant)
            ->setParameter('scope', $scope)
            ->orderBy('cg.scopeId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all tenants with hierarchical governance for a specific scope
     * (useful for propagating changes from parent)
     */
    public function findHierarchicalSubsidiaries(
        Tenant $parent,
        string $scope,
        ?string $scopeId = null
    ): array {
        $qb = $this->createQueryBuilder('cg')
            ->where('cg.parent = :parent')
            ->andWhere('cg.governanceModel = :model')
            ->andWhere('cg.scope = :scope')
            ->setParameter('parent', $parent)
            ->setParameter('model', GovernanceModel::HIERARCHICAL)
            ->setParameter('scope', $scope);

        if ($scopeId !== null) {
            $qb->andWhere('cg.scopeId = :scopeId')
               ->setParameter('scopeId', $scopeId);
        } else {
            $qb->andWhere('cg.scopeId IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get statistics about governance distribution
     */
    public function getGovernanceStatistics(Tenant $tenant): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                governance_model,
                COUNT(*) as count
            FROM corporate_governance
            WHERE tenant_id = :tenantId
            GROUP BY governance_model
        ';

        $result = $conn->executeQuery($sql, ['tenantId' => $tenant->getId()])->fetchAllAssociative();

        $stats = [
            'hierarchical' => 0,
            'shared' => 0,
            'independent' => 0,
            'total' => 0,
        ];

        foreach ($result as $row) {
            $model = $row['governance_model'];
            $count = (int) $row['count'];
            $stats[$model] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Check if any governance rules exist for this tenant
     */
    public function hasAnyGovernance(Tenant $tenant): bool
    {
        return $this->count(['tenant' => $tenant]) > 0;
    }

    /**
     * Delete all governance rules for a specific scope
     */
    public function deleteByScope(Tenant $tenant, string $scope, ?string $scopeId = null): int
    {
        $qb = $this->createQueryBuilder('cg')
            ->delete()
            ->where('cg.tenant = :tenant')
            ->andWhere('cg.scope = :scope')
            ->setParameter('tenant', $tenant)
            ->setParameter('scope', $scope);

        if ($scopeId !== null) {
            $qb->andWhere('cg.scopeId = :scopeId')
               ->setParameter('scopeId', $scopeId);
        } else {
            $qb->andWhere('cg.scopeId IS NULL');
        }

        return $qb->getQuery()->execute();
    }

    /**
     * Get scope breakdown (how many rules per scope)
     */
    public function getScopeBreakdown(Tenant $tenant): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                scope,
                COUNT(*) as count,
                COUNT(CASE WHEN scope_id IS NOT NULL THEN 1 END) as specific_count,
                COUNT(CASE WHEN scope_id IS NULL THEN 1 END) as default_count
            FROM corporate_governance
            WHERE tenant_id = :tenantId
            GROUP BY scope
            ORDER BY scope
        ';

        return $conn->executeQuery($sql, ['tenantId' => $tenant->getId()])->fetchAllAssociative();
    }
}
