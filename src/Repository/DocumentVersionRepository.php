<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * F4 Evidence-Versioning — DocumentVersion repository.
 *
 * @extends ServiceEntityRepository<DocumentVersion>
 *
 * @method DocumentVersion|null find($id, $lockMode = null, $lockVersion = null)
 * @method DocumentVersion|null findOneBy(array $criteria, array $orderBy = null)
 * @method DocumentVersion[]    findAll()
 * @method DocumentVersion[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DocumentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentVersion::class);
    }

    /**
     * All versions for a document, ordered ascending by version number.
     *
     * @return DocumentVersion[]
     */
    public function findByDocument(Document $document): array
    {
        return $this->createQueryBuilder('dv')
            ->where('dv.document = :document')
            ->setParameter('document', $document)
            ->orderBy('dv.versionNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific version by document and version number.
     */
    public function findOneByDocumentAndNumber(Document $document, int $versionNumber): ?DocumentVersion
    {
        return $this->createQueryBuilder('dv')
            ->where('dv.document = :document')
            ->andWhere('dv.versionNumber = :number')
            ->setParameter('document', $document)
            ->setParameter('number', $versionNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find an existing version that matches the given SHA-256 hash for a document.
     * Used by EvidenceVersioningService for hash-match-detection (no duplicate upload).
     */
    public function findByDocumentAndHash(Document $document, string $contentHash): ?DocumentVersion
    {
        return $this->createQueryBuilder('dv')
            ->where('dv.document = :document')
            ->andWhere('dv.contentHash = :hash')
            ->setParameter('document', $document)
            ->setParameter('hash', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Latest (highest versionNumber) version for a document.
     */
    public function findLatestForDocument(Document $document): ?DocumentVersion
    {
        return $this->createQueryBuilder('dv')
            ->where('dv.document = :document')
            ->setParameter('document', $document)
            ->orderBy('dv.versionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count how many versions exist for a document.
     */
    public function countForDocument(Document $document): int
    {
        return (int) $this->createQueryBuilder('dv')
            ->select('COUNT(dv.id)')
            ->where('dv.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All active (is_active=true) versions across the tenant — used for analytics.
     *
     * @return DocumentVersion[]
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dv')
            ->where('dv.tenant = :tenant')
            ->andWhere('dv.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('dv.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
