<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use DateTime;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Document Repository
 *
 * Repository for querying Document entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Document>
 *
 * @method Document|null find($id, $lockMode = null, $lockVersion = null)
 * @method Document|null findOneBy(array $criteria, array $orderBy = null)
 * @method Document[]    findAll()
 * @method Document[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Find non-archived documents attached to a specific entity.
     *
     * @param string $entityType Entity class name (e.g., 'Risk', 'Asset', 'Control')
     * @param int $entityId Entity identifier
     * @return Document[] Array of Document entities sorted by upload date (newest first)
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.entityType = :entityType')
            ->andWhere('d.entityId = :entityId')
            ->andWhere('d.isArchived = false')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find non-archived documents by category.
     *
     * @param string $category Document category (e.g., 'policy', 'procedure', 'evidence')
     * @return Document[] Array of Document entities sorted by upload date (newest first)
     */
    public function findByCategory(string $category): array
    {
        return $this->findBy(['category' => $category, 'isArchived' => false], ['uploadedAt' => 'DESC']);
    }

    /**
     * Find all documents for a tenant (own documents only)
     *
     * @param Tenant $tenant The tenant to find documents for
     * @return Document[] Array of Document entities
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Transient lifecycle statuses where action is still expected. Terminal
     * statuses (approved/published/archived/rejected/cancelled) are excluded.
     *
     * @var string[]
     */
    public const array NON_TERMINAL_LIFECYCLE_STATUSES = [
        'draft',
        'in_review',
        'in_investigation',
        'in_progress',
        'in_triage',
        'under_assessment',
    ];

    /**
     * Documents stuck in a non-terminal lifecycle status for longer than
     * $days. Shared by LifecycleStuckInStatusRule (the Alva hint) and the
     * document index `focus=lifecycle_stuck` filter, so the hint deep-links to
     * EXACTLY the documents it counts.
     *
     * @return Document[]
     */
    public function findStuckInLifecycle(Tenant $tenant, int $days): array
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d days', $days));

        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:nonTerminal)')
            ->andWhere('d.updatedAt < :threshold')
            ->setParameter('tenant', $tenant)
            ->setParameter('nonTerminal', self::NON_TERMINAL_LIFECYCLE_STATUSES)
            ->setParameter('threshold', $threshold)
            ->orderBy('d.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved documents with NO policy-acknowledgement record — awareness
     * audit gap (ISO 27001 A.6.3 / Cl. 7.3). Shared single source of truth for
     * ApprovedDocOhneAcknowledgementRule (the Alva hint) and the document index
     * `focus=no_ack` filter, so the hint deep-links to EXACTLY the documents it
     * counts.
     *
     * @return Document[]
     */
    public function findApprovedWithoutAcknowledgement(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status = :approved')
            ->andWhere('d.id NOT IN (
                SELECT IDENTITY(pa.document) FROM App\Entity\PolicyAcknowledgement pa
                WHERE pa.document IS NOT NULL
            )')
            ->setParameter('tenant', $tenant)
            ->setParameter('approved', 'approved')
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Phase 9.P2.1 — inheritable documents visible to a subsidiary
     * from its ancestor chain. Returns only documents that the ancestor
     * has explicitly marked inheritable=true (and that are still active).
     *
     * Unlike findByTenantIncludingParent which returns *all* ancestor
     * docs indiscriminately, this method respects the holding-side
     * intent: not every internal paper should propagate downstream.
     *
     * @return Document[]
     */
    public function findInheritedForTenant(Tenant $tenant): array
    {
        $ancestors = $tenant->getAllAncestors();
        if ($ancestors === []) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->where('d.tenant IN (:ancestors)')
            ->andWhere('d.inheritable = :true')
            ->andWhere('d.isArchived = false')
            ->setParameter('ancestors', $ancestors)
            ->setParameter('true', true)
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find documents by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited documents from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find documents for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Document[] Array of Document entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, Tenant|null $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant);

        // Include documents from all ancestors in the hierarchy
        if ($ancestors !== []) {
            $queryBuilder->orWhere('d.tenant IN (:ancestors) AND d.isArchived = false')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find documents by category for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @param string $category Document category
     * @return Document[] Array of Document entities
     */
    public function findByCategoryAndTenant(Tenant $tenant, string $category): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.category = :category')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('category', $category)
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find documents by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated documents from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find documents for
     * @return Document[] Array of Document entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant);

        // Include documents from all subsidiaries in the hierarchy
        if ($subsidiaries !== []) {
            $queryBuilder->orWhere('d.tenant IN (:subsidiaries) AND d.isArchived = false')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find approved, non-archived Documents whose review-cycle is overdue —
     * i.e. nextReviewDate < asOf. Surfaces in My-Day per Audit V4 V4-LB-1.
     *
     * @return Document[] Sorted by nextReviewDate ASC (most overdue first)
     */
    public function findReviewOverdue(Tenant $tenant, ?DateTimeInterface $asOf = null): array
    {
        $asOf ??= new DateTime('today');

        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->andWhere('d.status = :approved')
            ->andWhere('d.nextReviewDate IS NOT NULL')
            ->andWhere('d.nextReviewDate < :asOf')
            ->setParameter('tenant', $tenant)
            ->setParameter('approved', 'approved')
            ->setParameter('asOf', $asOf)
            ->orderBy('d.nextReviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * V4-EF-7 CM-Bucket — Documents with status='in_review' for a given tenant.
     * The CM sees all in-review documents when the filter is tenant-scoped
     * (CM is responsible for the approval queue, not just their own documents).
     * Optionally restrict to documents whose lastEffectivenessReviewBy matches the user
     * to limit noise when the bucket is shown to ROLE_COMPLIANCE_MANAGER.
     *
     * @return Document[] Sorted by id ASC (oldest review first = most urgent)
     */
    public function findPendingApprovalForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->andWhere('d.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', DocumentStatus::InReview->value)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
