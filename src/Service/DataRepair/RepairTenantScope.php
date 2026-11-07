<?php

declare(strict_types=1);

namespace App\Service\DataRepair;

use App\Entity\Tenant;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\Job\JobTenantScope;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Which tenants a data-repair action may touch: a SUPER_ADMIN (ADMIN_GLOBAL_OP)
 * everything, a tenant admin only its own tenant tree. The scope travels into the
 * async job as the `scope` arg (shape of {@see JobTenantScope}) and is re-checked there.
 */
final readonly class RepairTenantScope
{
    public const string ARG = 'scope';

    public function __construct(
        private Security $security,
        private TenantContext $tenantContext,
        private JobTenantScope $jobTenantScope,
    ) {
    }

    public function isGlobal(): bool
    {
        return $this->security->isGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP);
    }

    /** Whether the current user may move data into / out of $tenant. */
    public function canAccess(Tenant $tenant): bool
    {
        if ($this->isGlobal()) {
            return true;
        }
        foreach ($this->tenantContext->getAccessibleTenants() as $accessible) {
            if ($accessible->getId() === $tenant->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{tenantId: int}|array{allTenants: true} */
    public function jobArg(): array
    {
        return $this->jobTenantScope->argsForCurrentUser();
    }

    /**
     * Whether $tenant lies inside the scope captured in the job args.
     *
     * @param array<string, mixed> $scopeArg
     */
    public function jobScopeCovers(array $scopeArg, ?Tenant $tenant): bool
    {
        $root = $this->jobTenantScope->resolveArgs($scopeArg);
        if ($root === null) {
            return true;
        }
        if ($tenant === null) {
            return false;
        }
        if ($root->getId() === $tenant->getId()) {
            return true;
        }
        foreach ($root->getAllSubsidiaries() as $sub) {
            if ($sub->getId() === $tenant->getId()) {
                return true;
            }
        }

        return false;
    }
}
