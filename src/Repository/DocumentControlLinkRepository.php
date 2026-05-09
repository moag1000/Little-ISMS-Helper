<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentControlLink>
 *
 * @method DocumentControlLink|null find($id, $lockMode = null, $lockVersion = null)
 * @method DocumentControlLink|null findOneBy(array $criteria, array $orderBy = null)
 * @method DocumentControlLink[]    findAll()
 * @method DocumentControlLink[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DocumentControlLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentControlLink::class);
    }

    public function findOneByDocumentAndControl(Document $document, Control $control): ?DocumentControlLink
    {
        return $this->findOneBy(['document' => $document, 'control' => $control]);
    }

    /**
     * @return DocumentControlLink[]
     */
    public function findByDocument(Document $document): array
    {
        return $this->findBy(['document' => $document]);
    }

    /**
     * @return DocumentControlLink[]
     */
    public function findByControl(Control $control): array
    {
        return $this->findBy(['control' => $control]);
    }
}
