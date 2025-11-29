<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Document;
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
}
