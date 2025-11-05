<?php

namespace App\Repository;

use App\Entity\AuditChecklist;
use App\Entity\InternalAudit;
use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditChecklist>
 */
class AuditChecklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditChecklist::class);
    }

    /**
     * Find checklist items for an audit
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
     * Find checklist items for a specific framework within an audit
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
     * Get audit statistics
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
     * Find items with non-compliances
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
     * Find items not yet checked
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
     * Get checklist grouped by framework
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
     * Get checklist items for detailed requirements only
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
