<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Explicit tenant scoping for aggregations that may run outside an HTTP
 * request (KPI snapshot cron, scheduled reports, jobs), where the Doctrine
 * tenant filter is not armed and findAll() reads every organisation's
 * records.
 *
 * Scope resolution ({@see resolve()}): the explicit tenant, else the
 * current TenantContext tenant (the logged-in user's organisation in a
 * request; whatever a cron pinned via setCurrentTenant()).
 *
 * Without a tenant there is no silent unscoped fallback:
 *  - only an explicit instance-wide actor ({@see isInstanceWide()}: a
 *    ROLE_SUPER_ADMIN without an organisation) reads all records;
 *  - everyone else — notably a CLI process that set no tenant — gets an
 *    EMPTY result (fail closed) and a warning in the log.
 */
final class TenantRecords
{
    public function __construct(
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?Security $security = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function resolve(?Tenant $tenant): ?Tenant
    {
        return $tenant ?? $this->tenantContext?->getCurrentTenant();
    }

    /** True only for a ROLE_SUPER_ADMIN acting without an organisation. */
    public function isInstanceWide(): bool
    {
        return $this->security?->isGranted('ROLE_SUPER_ADMIN') === true;
    }

    /**
     * @template T of object
     * @param EntityRepository<T> $repository
     * @return array<int, T>
     */
    public function all(EntityRepository $repository, ?Tenant $tenant): array
    {
        $scope = $this->resolve($tenant);
        if ($scope instanceof Tenant) {
            return $repository->findBy(['tenant' => $scope]);
        }
        if ($this->isInstanceWide()) {
            return $repository->findAll();
        }

        $this->logger?->warning('Unscoped tenant read refused: no tenant and no instance-wide actor', [
            'repository' => $repository::class,
        ]);

        return [];
    }
}
