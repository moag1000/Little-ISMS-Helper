<?php

namespace App\Repository;

use App\Entity\AuditChecklist;
use App\Entity\InternalAudit;
use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Audit Checklist Repository
 *
 * Repository for querying AuditChecklist entities with comprehensive audit statistics and verification tracking.
 *
 * @extends ServiceEntityRepository<AuditChecklist>
 *
 * @method AuditChecklist|null find($id, $lockMode = null, $lockVersion = null)
 * @method AuditChecklist|null findOneBy(array $criteria, array $orderBy = null)
 * @method AuditChecklist[]    findAll()
 * @method AuditChecklist[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AuditChecklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditChecklist::class);
    }

    /**
     * Find all checklist items for a specific internal audit.
     *
     * @param InternalAudit $audit Internal audit entity
     * @return AuditChecklist[] Array of checklist items sorted by creation date
     */
    public function findByAudit(InternalAudit $audit): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.audit = :audit')
            ->setParameter('audit', $audit)
            ->orderBy('ac.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find checklist items for a specific framework within an audit.
     *
     * @param InternalAudit $audit Internal audit entity
     * @param ComplianceFramework $framework Compliance framework to filter by
     * @return AuditChecklist[] Array of checklist items sorted by requirement ID
     */
    public function findByAuditAndFramework(InternalAudit $audit, ComplianceFramework $framework): array
    {
        return $this->createQueryBuilder('ac')
            ->join('ac.requirement', 'r')
            ->where('ac.audit = :audit')
            ->andWhere('r.framework = :framework')
            ->setParameter('audit', $audit)
            ->setParameter('framework', $framework)
            ->orderBy('r.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get comprehensive audit statistics for dashboard and reporting.
     *
     * @param InternalAudit $audit Internal audit entity
     * @return array<string, int|float> Statistics array containing:
     *   - total: Total checklist items
     *   - compliant: Count of compliant items
     *   - partial: Count of partially compliant items
     *   - non_compliant: Count of non-compliant items
     *   - not_checked: Count of items not yet verified
     *   - verified: Count of items with verification date
     *   - with_findings: Count of items with documented findings
     *   - average_score: Average compliance score (0-100)
     */
    public function getAuditStatistics(InternalAudit $audit): array
    {
        $qb = $this->createQueryBuilder('ac');

        $total = $qb->select('COUNT(ac.id)')
            ->where('ac.audit = :audit')
            ->setParameter('audit', $audit)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,

            'compliant' => $this->createQueryBuilder('ac')
                ->select('COUNT(ac.id)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.verificationStatus = :status')
                ->setParameter('audit', $audit)
                ->setParameter('status', 'compliant')
                ->getQuery()
                ->getSingleScalarResult(),

            'partial' => $this->createQueryBuilder('ac')
                ->select('COUNT(ac.id)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.verificationStatus = :status')
                ->setParameter('audit', $audit)
                ->setParameter('status', 'partial')
                ->getQuery()
                ->getSingleScalarResult(),

            'non_compliant' => $this->createQueryBuilder('ac')
                ->select('COUNT(ac.id)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.verificationStatus = :status')
                ->setParameter('audit', $audit)
                ->setParameter('status', 'non_compliant')
                ->getQuery()
                ->getSingleScalarResult(),

            'not_checked' => $this->createQueryBuilder('ac')
                ->select('COUNT(ac.id)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.verificationStatus = :status')
                ->setParameter('audit', $audit)
                ->setParameter('status', 'not_checked')
                ->getQuery()
                ->getSingleScalarResult(),

            'verified' => $this->createQueryBuilder('ac')
                ->select('COUNT(ac.id)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.verifiedAt IS NOT NULL')
                ->setParameter('audit', $audit)
                ->getQuery()
                ->getSingleScalarResult(),

            'with_findings' => $this->createQueryBuilder('ac')
                ->select('COUNT(ac.id)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.findings IS NOT NULL')
                ->andWhere('ac.findings != :empty')
                ->setParameter('audit', $audit)
                ->setParameter('empty', '')
                ->getQuery()
                ->getSingleScalarResult(),

            'average_score' => $this->createQueryBuilder('ac')
                ->select('AVG(ac.complianceScore)')
                ->where('ac.audit = :audit')
                ->andWhere('ac.verificationStatus != :na')
                ->setParameter('audit', $audit)
                ->setParameter('na', 'not_applicable')
                ->getQuery()
                ->getSingleScalarResult() ?? 0,
        ];
    }

    /**
     * Find checklist items with non-compliances for corrective action planning.
     *
     * @param InternalAudit $audit Internal audit entity
     * @return AuditChecklist[] Array of non-compliant items sorted by score (worst first)
     */
    public function findNonCompliances(InternalAudit $audit): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.audit = :audit')
            ->andWhere('ac.verificationStatus IN (:statuses)')
            ->setParameter('audit', $audit)
            ->setParameter('statuses', ['non_compliant', 'partial'])
            ->orderBy('ac.complianceScore', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find checklist items not yet verified for audit progress tracking.
     *
     * @param InternalAudit $audit Internal audit entity
     * @return AuditChecklist[] Array of unchecked items
     */
    public function findNotChecked(InternalAudit $audit): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.audit = :audit')
            ->andWhere('ac.verificationStatus = :status')
            ->setParameter('audit', $audit)
            ->setParameter('status', 'not_checked')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get checklist items organized by compliance framework for structured reporting.
     *
     * @param InternalAudit $audit Internal audit entity
     * @return array<string, AuditChecklist[]> Associative array with framework names as keys
     */
    public function getGroupedByFramework(InternalAudit $audit): array
    {
        $items = $this->createQueryBuilder('ac')
            ->join('ac.requirement', 'r')
            ->join('r.framework', 'f')
            ->where('ac.audit = :audit')
            ->setParameter('audit', $audit)
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('r.requirementId', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($items as $item) {
            $frameworkName = $item->getRequirement()->getFramework()->getName();
            if (!isset($grouped[$frameworkName])) {
                $grouped[$frameworkName] = [];
            }
            $grouped[$frameworkName][] = $item;
        }

        return $grouped;
    }

    /**
     * Find checklist items for detailed requirements only (excluding high-level categories).
     *
     * @param InternalAudit $audit Internal audit entity
     * @return AuditChecklist[] Array of detailed requirement items sorted by requirement ID
     */
    public function findDetailedRequirements(InternalAudit $audit): array
    {
        return $this->createQueryBuilder('ac')
            ->join('ac.requirement', 'r')
            ->where('ac.audit = :audit')
            ->andWhere('r.requirementType IN (:types)')
            ->setParameter('audit', $audit)
            ->setParameter('types', ['detailed', 'sub_requirement'])
            ->orderBy('r.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
