<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\MappingGapItem;
use App\Entity\Tenant;
use App\Repository\ComplianceMappingRepository;
use App\Service\TenantContext;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant visibility of compliance mappings. A mapping is shared when both
 * requirements are global catalogue rows (owner NULL) and visible to every
 * tenant; otherwise it belongs to the tenant that uploaded a requirement and
 * is invisible (404) and read-only-nothing for every other tenant.
 * SUPER_ADMIN sees everything.
 */
final class MappingAccessGuard
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    public function currentTenant(): ?Tenant
    {
        return $this->tenantContext->getCurrentTenant();
    }

    public function isVisible(ComplianceMapping $mapping): bool
    {
        $owner = $mapping->getOwnerTenant();
        if ($owner === null || $this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        $current = $this->tenantContext->getCurrentTenant();

        return $current !== null && $owner->getId() === $current->getId();
    }

    public function canSeeAll(): bool
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN');
    }

    /** 404 for a mapping owned by another tenant. */
    public function assertVisible(ComplianceMapping $mapping): void
    {
        if (!$this->isVisible($mapping)) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Global catalogue requirement or one uploaded by the current tenant.
     *
     * @phpstan-assert-if-true ComplianceRequirement $requirement
     */
    public function isRequirementUsable(?ComplianceRequirement $requirement): bool
    {
        $owner = $requirement?->getUploadTenant();
        if ($owner === null || $this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $requirement !== null;
        }

        return $owner->getId() === $this->tenantContext->getCurrentTenant()?->getId();
    }

    /**
     * Restricts a ComplianceMapping query builder to the visible mappings
     * (shared + own uploads); SUPER_ADMIN without a tenant stays unrestricted.
     */
    public function scopeQuery(QueryBuilder $qb, string $alias = 'cm'): QueryBuilder
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->security->isGranted('ROLE_SUPER_ADMIN') ? $qb : $qb->andWhere('1 = 0');
        }

        return $qb
            ->join($alias . '.sourceRequirement', 'scope_sr')
            ->join($alias . '.targetRequirement', 'scope_tr')
            ->andWhere('((scope_sr.uploadTenant IS NULL AND scope_tr.uploadTenant IS NULL) '
                . 'OR scope_sr.uploadTenant = :scope_tenant OR scope_tr.uploadTenant = :scope_tenant)')
            ->setParameter('scope_tenant', $tenant);
    }

    /**
     * Mappings visible to the current tenant (shared + own uploads).
     *
     * @return list<ComplianceMapping>
     */
    public function visibleMappings(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant !== null) {
            return array_values($this->mappingRepository->findAllForTenant($tenant));
        }

        return $this->security->isGranted('ROLE_SUPER_ADMIN')
            ? array_values($this->mappingRepository->findAllGlobal())
            : [];
    }

    /**
     * @param iterable<ComplianceMapping> $mappings
     * @return list<ComplianceMapping>
     */
    public function filterVisible(iterable $mappings): array
    {
        $out = [];
        foreach ($mappings as $mapping) {
            if ($this->isVisible($mapping)) {
                $out[] = $mapping;
            }
        }

        return $out;
    }

    /**
     * @param iterable<MappingGapItem> $gaps
     * @return list<MappingGapItem>
     */
    public function filterVisibleGaps(iterable $gaps): array
    {
        $out = [];
        foreach ($gaps as $gap) {
            $mapping = $gap->getMapping();
            if ($mapping === null || $this->isVisible($mapping)) {
                $out[] = $gap;
            }
        }

        return $out;
    }
}
