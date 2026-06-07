<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SourceConversionConfig;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SourceConversionConfig>
 */
class SourceConversionConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SourceConversionConfig::class);
    }

    public function findForTenantAndSlug(Tenant $tenant, string $slug): ?SourceConversionConfig
    {
        return $this->findOneBy(['tenant' => $tenant, 'sourceSlug' => $slug]);
    }

    /**
     * @return array<string, SourceConversionConfig> keyed by sourceSlug
     */
    public function findForTenantKeyedBySlug(Tenant $tenant): array
    {
        $out = [];
        foreach ($this->findBy(['tenant' => $tenant]) as $config) {
            /** @var SourceConversionConfig $config */
            $out[(string) $config->getSourceSlug()] = $config;
        }
        return $out;
    }
}
