<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IctProviderLibrary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IctProviderLibrary>
 */
class IctProviderLibraryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IctProviderLibrary::class);
    }

    /** @return IctProviderLibrary[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['category' => 'ASC', 'name' => 'ASC']);
    }

    public function findOneByCode(string $code): ?IctProviderLibrary
    {
        return $this->findOneBy(['code' => $code]);
    }
}
