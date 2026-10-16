<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\Tenant\TenantNotFoundException;
use App\Job\JobContext;
use App\Repository\TenantRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Carries the tenant scope of an async export job from the dispatching
 * request into the job, explicitly.
 *
 * Jobs must not rely on the Doctrine tenant_filter: it is only armed on
 * kernel.request (and by ExecuteJobHandler in Messenger mode), so a job that
 * calls findAll() reads across ALL organisations as soon as it runs outside
 * the original request. Instead the dispatcher captures the scope via
 * {@see self::argsForCurrentUser()} and the job resolves it via
 * {@see self::resolve()} and passes the tenant into every query.
 *
 * Two shapes only:
 *  - `tenantId: int`    → scope to that organisation (the normal case)
 *  - `allTenants: true` → instance-wide, ONLY captured for a SUPER_ADMIN who
 *                         has no organisation of their own (mirrors the sync
 *                         exports, where such an account sees every tenant)
 *
 * Anything else — missing args, an unknown tenant id — fails the job instead
 * of silently exporting every organisation's data.
 */
final readonly class JobTenantScope
{
    public const string ARG_TENANT_ID = 'tenantId';
    public const string ARG_ALL_TENANTS = 'allTenants';

    public function __construct(
        private Security $security,
        private TenantRepository $tenantRepository,
    ) {
    }

    /**
     * Job args describing the calling user's tenant scope.
     *
     * @return array{tenantId: int}|array{allTenants: true}
     *
     * @throws AccessDeniedException when the caller has no organisation and
     *                               is not a SUPER_ADMIN
     */
    public function argsForCurrentUser(): array
    {
        $user = $this->security->getUser();
        $tenantId = $user instanceof User ? $user->getTenant()?->getId() : null;

        if ($tenantId !== null) {
            return [self::ARG_TENANT_ID => $tenantId];
        }

        if ($user instanceof User && $this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return [self::ARG_ALL_TENANTS => true];
        }

        throw new AccessDeniedException('An export requires an organisation context.');
    }

    /**
     * Resolve the scope captured by {@see self::argsForCurrentUser()} from a
     * running job's args.
     *
     * @return Tenant|null the organisation to scope to; null ONLY for an
     *                     explicit instance-wide (allTenants) export
     *
     * @throws TenantNotFoundException when no scope was passed or the tenant
     *                                 no longer exists
     */
    public function resolve(JobContext $ctx): ?Tenant
    {
        return $this->resolveArgs($ctx->args());
    }

    /**
     * Resolve the calling user's scope directly — for synchronous exports
     * that apply the same rules as their async counterparts.
     *
     * @throws AccessDeniedException when the caller has no organisation and
     *                               is not a SUPER_ADMIN
     */
    public function currentUserScope(): ?Tenant
    {
        return $this->resolveArgs($this->argsForCurrentUser());
    }

    /**
     * @param array<string, mixed> $args
     *
     * @throws TenantNotFoundException when no scope was passed or the tenant
     *                                 no longer exists
     */
    public function resolveArgs(array $args): ?Tenant
    {
        $tenantId = $args[self::ARG_TENANT_ID] ?? null;

        if ($tenantId === null || $tenantId === '') {
            if (($args[self::ARG_ALL_TENANTS] ?? null) === true) {
                return null;
            }

            throw new TenantNotFoundException(
                null,
                'Refusing to run export job without a tenant scope: it would read across all organisations.',
            );
        }

        if (!is_int($tenantId) && !(is_string($tenantId) && ctype_digit($tenantId))) {
            throw new TenantNotFoundException(is_scalar($tenantId) ? (string) $tenantId : get_debug_type($tenantId));
        }

        $tenant = $this->tenantRepository->find((int) $tenantId);
        if (!$tenant instanceof Tenant) {
            throw TenantNotFoundException::byId((int) $tenantId);
        }

        return $tenant;
    }
}
