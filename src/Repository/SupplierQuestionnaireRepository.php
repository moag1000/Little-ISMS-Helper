<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SupplierQuestionnaire;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierQuestionnaire>
 */
class SupplierQuestionnaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierQuestionnaire::class);
    }

    public function findOneByToken(string $token): ?SupplierQuestionnaire
    {
        return $this->findOneBy(['publicToken' => $token]);
    }

    /** @return SupplierQuestionnaire[] */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['createdAt' => 'DESC']);
    }
}
