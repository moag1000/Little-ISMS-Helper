<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentSection>
 *
 * @method DocumentSection|null find($id, $lockMode = null, $lockVersion = null)
 * @method DocumentSection|null findOneBy(array $criteria, array $orderBy = null)
 * @method DocumentSection[]    findAll()
 * @method DocumentSection[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DocumentSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSection::class);
    }

    /**
     * All sections for a host document, ordered by sectionKey for stable
     * UI display.
     *
     * @return DocumentSection[]
     */
    public function findByDocument(Document $document): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.document = :doc')
            ->setParameter('doc', $document)
            ->orderBy('s.sectionKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sections for a given document still missing DPO sign-off — fuels
     * the privacy-section-gate logic in PolicySectionApprovalService.
     *
     * @return DocumentSection[]
     */
    public function findPendingApprovalForDocument(Document $document): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.document = :doc')
            ->andWhere('s.status IN (:pending)')
            ->setParameter('doc', $document)
            ->setParameter('pending', [
                DocumentSection::STATUS_DRAFT,
                DocumentSection::STATUS_DPO_SIGN_OFF,
                DocumentSection::STATUS_REJECTED,
            ])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return DocumentSection[]
     */
    public function findApprovedForDocument(Document $document): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.document = :doc')
            ->andWhere('s.status = :status')
            ->setParameter('doc', $document)
            ->setParameter('status', DocumentSection::STATUS_APPROVED)
            ->getQuery()
            ->getResult();
    }

    public function findOneByDocumentAndKey(Document $document, string $sectionKey): ?DocumentSection
    {
        return $this->findOneBy(['document' => $document, 'sectionKey' => $sectionKey]);
    }

    /**
     * True iff every section row for the host document has reached the
     * `approved` status. Used by PolicySectionApprovalService to decide
     * whether the host workflow may advance from
     * `privacy_section_gate → top_mgmt_signoff`.
     */
    public function allSectionsApproved(Document $document): bool
    {
        $sections = $this->findByDocument($document);
        if ($sections === []) {
            return true;
        }
        foreach ($sections as $section) {
            if (!$section->isApproved()) {
                return false;
            }
        }
        return true;
    }
}
