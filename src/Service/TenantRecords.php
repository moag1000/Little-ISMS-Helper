<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use Doctrine\ORM\EntityRepository;

/**
 * Explicit tenant scoping for aggregations that may run outside an HTTP
 * request (KPI snapshot cron, jobs), where the Doctrine tenant filter is not
 * armed and findAll() reads every organisation's records.
 */
final class TenantRecords
{
    /**
     * The tenant's records; all records only without a tenant (request-scoped
     * callers, where the tenant filter applies).
     *
     * @template T of object
     * @param EntityRepository<T> $repository
     * @return array<int, T>
     */
    public static function all(EntityRepository $repository, ?Tenant $tenant): array
    {
        return $tenant instanceof Tenant ? $repository->findBy(['tenant' => $tenant]) : $repository->findAll();
    }
}
