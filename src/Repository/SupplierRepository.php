<?php

declare(strict_types=1);

namespace App\Repository;

use DateTime;
use App\Entity\Tenant;
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
            ->setParameter('now', new DateTime())
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
        $queryBuilder = $this->createQueryBuilder('s');

        $total = $queryBuilder->select('COUNT(s.id)')
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
            ->setParameter('now', new DateTime())
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $nonCompliant = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.hasISO27001 = :false OR s.hasDPA = :false')
            ->andWhere('s.status = :active')
            ->andWhere('s.criticality IN (:criticalities)')
            ->setParameter('false', false)
            ->setParameter('active', 'active')
            ->setParameter('criticalities', ['critical', 'high'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'critical' => $critical,
            'iso27001_certified' => $iso27001,
            'overdue_assessments' => $overdue,
            'non_compliant' => $nonCompliant,
            'compliance_rate' => $total > 0 ? round(($iso27001 / $total) * 100, 2) : 0
        ];
    }

    /**
     * Find all suppliers for a tenant (own suppliers only)
     *
     * @param Tenant $tenant The tenant to find suppliers for
     * @return Supplier[] Array of Supplier entities
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Phase 9.P2.5 — Cross-tenant supplier roll-up.
     *
     * Collapses supplier rows from the given tenant subtree into a
     * deduplicated set keyed by LEI (preferred, ISO 17442 globally
     * unique) or by normalized name (lowercase + trim) when no LEI is
     * present. Each entry lists every tenant in the subtree that has a
     * supplier record matching that identity.
     *
     * Intended for the Group-CISO: answers "which suppliers serve
     * multiple Konzern-Töchter, and could we consolidate contracts or
     * align due diligence?" (DORA Art. 28.3 Sub-Outsourcing-Kette,
     * ISO 27001 A.5.19 Supplier Relationships).
     *
     * @param Tenant[] $tenants
     * @return array<string, array{
     *   key: string,
     *   representative: Supplier,
     *   references: list<Supplier>,
     *   tenant_codes: list<string>,
     *   max_criticality: ?string,
     *   has_lei: bool
     * }>
     */
    public function findGroupedForTenants(array $tenants): array
    {
        if ($tenants === []) {
            return [];
        }

        $suppliers = $this->createQueryBuilder('s')
            ->where('s.tenant IN (:tenants)')
            ->setParameter('tenants', $tenants)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        $criticalityRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $grouped = [];
        foreach ($suppliers as $supplier) {
            $lei = (string) $supplier->getLeiCode();
            if ($lei !== '') {
                $key = 'lei:' . strtoupper($lei);
                $hasLei = true;
            } else {
                $key = 'name:' . mb_strtolower(trim((string) $supplier->getName()));
                $hasLei = false;
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'key' => $key,
                    'representative' => $supplier,
                    'references' => [],
                    'tenant_codes' => [],
                    'max_criticality' => null,
                    'has_lei' => $hasLei,
                ];
            }
            $grouped[$key]['references'][] = $supplier;
            $code = (string) $supplier->getTenant()?->getCode();
            if ($code !== '' && !in_array($code, $grouped[$key]['tenant_codes'], true)) {
                $grouped[$key]['tenant_codes'][] = $code;
            }

            $crit = (string) $supplier->getCriticality();
            $currentMax = $grouped[$key]['max_criticality'];
            if (isset($criticalityRank[$crit]) && (
                $currentMax === null
                || ($criticalityRank[$crit] ?? 0) > ($criticalityRank[$currentMax] ?? 0)
            )) {
                $grouped[$key]['max_criticality'] = $crit;
            }
        }

        return $grouped;
    }

    /**
     * Find suppliers by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited suppliers from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find suppliers for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Supplier[] Array of Supplier entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, Tenant|null $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include suppliers from all ancestors in the hierarchy
        if ($ancestors !== []) {
            $queryBuilder->orWhere('s.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get supplier statistics for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return array Supplier statistics
     */
    public function getStatisticsByTenant(Tenant $tenant): array
    {
        $queryBuilder = $this->createQueryBuilder('s');

        $total = $queryBuilder->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $critical = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.criticality = :critical')
            ->andWhere('s.status = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('critical', 'critical')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $iso27001 = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.hasISO27001 = :true')
            ->andWhere('s.status = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('true', true)
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $overdueAssessments = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('(s.nextAssessmentDate < :now OR (s.lastSecurityAssessment IS NULL AND s.status = :active))')
            ->setParameter('tenant', $tenant)
            ->setParameter('now', new DateTime())
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $nonCompliant = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('(s.hasISO27001 = :false OR s.hasDPA = :false)')
            ->andWhere('s.status = :active')
            ->andWhere('s.criticality IN (:criticalities)')
            ->setParameter('tenant', $tenant)
            ->setParameter('false', false)
            ->setParameter('active', 'active')
            ->setParameter('criticalities', ['critical', 'high'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => (int) $total,
            'critical' => (int) $critical,
            'iso27001_certified' => (int) $iso27001,
            'overdue_assessments' => (int) $overdueAssessments,
            'non_compliant' => (int) $nonCompliant,
            'compliance_rate' => $total > 0 ? round(($iso27001 / $total) * 100, 2) : 0
        ];
    }

    /**
     * Find critical suppliers for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return Supplier[] Array of critical supplier entities
     */
    public function findCriticalSuppliersByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.criticality IN (:criticalities)')
            ->andWhere('s.status = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('criticalities', ['critical', 'high'])
            ->setParameter('active', 'active')
            ->orderBy('s.criticality', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find suppliers with overdue assessments for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return Supplier[] Array of suppliers with overdue assessments
     */
    public function findOverdueAssessmentsByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('(s.nextAssessmentDate < :now OR (s.lastSecurityAssessment IS NULL AND s.status = :active))')
            ->setParameter('tenant', $tenant)
            ->setParameter('now', new DateTime())
            ->setParameter('active', 'active')
            ->orderBy('s.criticality', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find non-compliant suppliers for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return Supplier[] Array of non-compliant supplier entities
     */
    public function findNonCompliantByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('(s.hasISO27001 = :false OR s.hasDPA = :false)')
            ->andWhere('s.status = :active')
            ->andWhere('s.criticality IN (:criticalities)')
            ->setParameter('tenant', $tenant)
            ->setParameter('false', false)
            ->setParameter('active', 'active')
            ->setParameter('criticalities', ['critical', 'high'])
            ->getQuery()
            ->getResult();
    }

    /**
     * Find suppliers by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated suppliers from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find suppliers for
     * @return Supplier[] Array of Supplier entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include suppliers from all subsidiaries in the hierarchy
        if ($subsidiaries !== []) {
            $queryBuilder->orWhere('s.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
