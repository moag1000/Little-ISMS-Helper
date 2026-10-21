<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;

/**
 * Explicit tenant scoping for aggregations that may run outside an HTTP
 * request (KPI snapshot cron, scheduled reports, jobs), where the Doctrine
 * tenant filter is not armed and findAll() reads every organisation's
 * records.
 *
 * Scope resolution ({@see resolve()}): the explicit tenant, else the
 * current TenantContext tenant (the logged-in user's organisation in a
 * request — a SUPER_ADMIN without organisation falls back to the default
 * tenant there, exactly like on record pages; whatever a cron pinned via
 * setCurrentTenant()).
 *
 * Without a tenant there is no silent unscoped fallback and no hidden
 * "instance-wide" role mode: the result is EMPTY (fail closed) with a
 * warning in the log. Reading every organisation's records is possible only
 * when a CLI/cron caller deliberately passes `$instanceWide = true` to
 * {@see all()}.
 */
final class TenantRecords
{
    public function __construct(
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function resolve(?Tenant $tenant): ?Tenant
    {
        return $tenant ?? $this->tenantContext?->getCurrentTenant();
    }

    /**
     * @template T of object
     * @param EntityRepository<T> $repository
     * @param bool $instanceWide explicit opt-in of a CLI/cron caller to read all organisations when no tenant resolves
     * @return array<int, T>
     */
    public function all(EntityRepository $repository, ?Tenant $tenant, bool $instanceWide = false): array
    {
        $scope = $this->resolve($tenant);
        if ($scope instanceof Tenant) {
            return $repository->findBy(['tenant' => $scope]);
        }
        if ($instanceWide) {
            return $repository->findAll();
        }

        $this->logger?->warning('Unscoped tenant read refused: no tenant and no explicit instance-wide opt-in', [
            'repository' => $repository::class,
        ]);

        return [];
    }
}
