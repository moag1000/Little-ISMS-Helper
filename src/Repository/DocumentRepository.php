<?php

namespace App\Repository;

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
     * @param \App\Entity\Tenant $tenant The tenant to find documents for
     * @return Document[] Array of Document entities
     */
    public function findByTenant($tenant): array
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
     * Find documents by tenant or parent tenant (for hierarchical governance)
     * This allows viewing inherited documents from parent companies
     *
     * @param \App\Entity\Tenant $tenant The tenant to find documents for
     * @param \App\Entity\Tenant|null $parentTenant Optional parent tenant for inherited documents
     * @return Document[] Array of Document entities (own + inherited)
     */
    public function findByTenantIncludingParent($tenant, $parentTenant = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant);

        if ($parentTenant) {
            $qb->orWhere('d.tenant = :parentTenant')
               ->setParameter('parentTenant', $parentTenant);
        }

        return $qb
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find documents by category for a specific tenant
     *
     * @param \App\Entity\Tenant $tenant The tenant
     * @param string $category Document category
     * @return Document[] Array of Document entities
     */
    public function findByCategoryAndTenant($tenant, string $category): array
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
}
