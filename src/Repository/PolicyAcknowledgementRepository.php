<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PolicyAcknowledgement>
 *
 * @method PolicyAcknowledgement|null find($id, $lockMode = null, $lockVersion = null)
 * @method PolicyAcknowledgement|null findOneBy(array $criteria, array $orderBy = null)
 * @method PolicyAcknowledgement[]    findAll()
 * @method PolicyAcknowledgement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PolicyAcknowledgementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PolicyAcknowledgement::class);
    }

    public function findOneFor(Tenant $tenant, Document $document, User $user, string $documentVersion): ?PolicyAcknowledgement
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'document' => $document,
            'user' => $user,
            'documentVersion' => $documentVersion,
        ]);
    }

    /**
     * @return PolicyAcknowledgement[]
     */
    public function findByDocument(Document $document): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.document = :document')
            ->setParameter('document', $document)
            ->orderBy('a.acknowledgedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
