<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Asset;
use App\Entity\AssetDependency;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetDependency>
 *
 * @method AssetDependency|null find($id, $lockMode = null, $lockVersion = null)
 * @method AssetDependency|null findOneBy(array $criteria, array $orderBy = null)
 * @method AssetDependency[]    findAll()
 * @method AssetDependency[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AssetDependencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetDependency::class);
    }

    /**
     * Edges where the given asset is the downstream side (i.e. its upstream
     * dependencies). Mirrors {@see Asset::getDependsOn()} but returns the
     * enriched edges with dependencyType / criticalityImpact metadata.
     *
     * @return AssetDependency[]
     */
    public function findOutgoingFor(Asset $asset): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.sourceAsset = :asset')
            ->setParameter('asset', $asset)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Edges where the given asset is the upstream side (i.e. assets that
     * depend on it).
     *
     * @return AssetDependency[]
     */
    public function findIncomingFor(Asset $asset): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.targetAsset = :asset')
            ->setParameter('asset', $asset)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All edges where both endpoints belong to the given tenant. Used by
     * the DORA RoI XBRL exporter (RT_05 asset-dependency-graph).
     *
     * @return AssetDependency[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.sourceAsset', 'src')
            ->where('src.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Indexed lookup: edges keyed by "{sourceId}:{targetId}" for fast
     * enrichment of the legacy {@see Asset::$dependsOn} adjacency list.
     *
     * @return array<string, AssetDependency>
     */
    public function findByTenantKeyed(Tenant $tenant): array
    {
        $rows = $this->findByTenant($tenant);
        $out = [];
        foreach ($rows as $row) {
            $sid = $row->getSourceAsset()?->getId();
            $tid = $row->getTargetAsset()?->getId();
            if ($sid === null || $tid === null) {
                continue;
            }
            $out[$sid . ':' . $tid] = $row;
        }
        return $out;
    }
}
