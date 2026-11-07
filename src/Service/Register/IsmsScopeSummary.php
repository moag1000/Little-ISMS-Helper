<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Location;
use App\Entity\Supplier;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISO 27001 clause 4.3: how many assets, locations, business processes and
 * suppliers of an organisation lie inside / outside the ISMS scope.
 */
final class IsmsScopeSummary
{
    private const TYPES = [
        'asset' => [Asset::class, 'app_asset_index'],
        'location' => [Location::class, 'app_location_index'],
        'business_process' => [BusinessProcess::class, 'app_business_process_index'],
        'supplier' => [Supplier::class, 'app_supplier_index'],
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, array{in: int, out: int, route: string}>
     */
    public function summarize(Tenant $tenant): array
    {
        $summary = [];
        foreach (self::TYPES as $type => [$class, $route]) {
            $summary[$type] = [
                'in' => $this->count($class, $tenant, true),
                'out' => $this->count($class, $tenant, false),
                'route' => $route,
            ];
        }

        return $summary;
    }

    /**
     * @param class-string $class
     */
    private function count(string $class, Tenant $tenant, bool $inScope): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($class, 'e')
            ->where('e.tenant = :t')
            ->andWhere('e.inIsmsScope = :s')
            ->setParameter('t', $tenant)
            ->setParameter('s', $inScope)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
