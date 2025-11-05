<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.documentType = :type')
            ->andWhere('d.status = :status')
            ->setParameter('type', $type)
            ->setParameter('status', 'active')
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByRelatedEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.relatedEntityType = :entityType')
            ->andWhere('d.relatedEntityId = :entityId')
            ->andWhere('d.status = :status')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('status', 'active')
            ->orderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findRecentDocuments(int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('d.uploadedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('d');

        return [
            'total' => $qb->select('COUNT(d.id)')
                ->where('d.status = :status')
                ->setParameter('status', 'active')
                ->getQuery()
                ->getSingleScalarResult(),
            'by_type' => $this->createQueryBuilder('d')
                ->select('d.documentType, COUNT(d.id) as count')
                ->where('d.status = :status')
                ->setParameter('status', 'active')
                ->groupBy('d.documentType')
                ->getQuery()
                ->getResult(),
            'total_size' => $qb->select('SUM(d.fileSize)')
                ->where('d.status = :status')
                ->setParameter('status', 'active')
                ->getQuery()
                ->getSingleScalarResult() ?? 0,
        ];
    }
}
