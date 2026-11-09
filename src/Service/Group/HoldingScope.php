<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Doctrine\HoldingIncidentFilter;
use App\Entity\Tenant;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Reading across the holding tree from inside a request (group reports).
 *
 * On every request the Doctrine tenant filter is armed with the user's own
 * organisation, so `findBy(['tenant' => $tree])` silently returns only the
 * holding's rows and every subsidiary looks empty. Group reports therefore
 * read through this class:
 *
 *  - {@see read()} lifts the filter for one callback. The callback MUST scope
 *    every query explicitly to the tenants it was handed (the caller's
 *    {@see TenantContext::getAccessibleTenants()} subtree);
 *  - {@see asTenant()} pins one subsidiary as the tenant context, so services
 *    that resolve the tenant from the context (module set, framework
 *    statistics, KPI catalog) compute that organisation's values. The
 *    previous context is always restored.
 *
 * Both arm {@see HoldingIncidentFilter} for the organisation looking (W6): its
 * own incidents count, another organisation's only when released to the
 * holding (visibleToHolding). Every number of a group view follows that rule.
 */
class HoldingScope
{
    private const string FILTER = 'tenant_filter';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function read(callable $fn): mixed
    {
        $filters = $this->entityManager->getFilters();
        $suspend = $filters->isEnabled(self::FILTER);
        if ($suspend) {
            $filters->suspend(self::FILTER);
        }
        $armed = $this->armIncidentLens();
        try {
            return $fn();
        } finally {
            if ($armed) {
                $filters->disable(HoldingIncidentFilter::NAME);
            }
            if ($suspend) {
                $filters->restore(self::FILTER);
            }
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function asTenant(Tenant $tenant, callable $fn): mixed
    {
        $before = $this->tenantContext->snapshot();
        $previous = $before['initialized'] ? $this->tenantContext->getCurrentTenant() : null;
        $parameter = $this->filterParameter();
        $armed = $this->armIncidentLens();
        $this->tenantContext->setCurrentTenant($tenant);
        try {
            return $fn();
        } finally {
            if ($armed) {
                $this->entityManager->getFilters()->disable(HoldingIncidentFilter::NAME);
            }
            if ($before['initialized']) {
                $this->tenantContext->setCurrentTenant($previous);
            } else {
                $this->tenantContext->reset();
            }
            // Put the request's own filter value back verbatim: re-arming from
            // the context would scope an instance operator, whose request runs
            // unscoped, to the default organisation.
            if ($parameter !== null && $this->entityManager->getFilters()->isEnabled(self::FILTER)) {
                $this->entityManager->getFilters()->getFilter(self::FILTER)
                    ->setParameter('tenant_id', $parameter['value'], $parameter['type']);
            }
        }
    }

    /**
     * Arms the incident lens for the organisation looking (the context before
     * any pinning). False when it was armed already (nested call keeps the
     * outer viewer) or there is no filter to arm.
     */
    private function armIncidentLens(): bool
    {
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(HoldingIncidentFilter::NAME) || !$filters->has(HoldingIncidentFilter::NAME)) {
            return false;
        }
        $viewer = $this->tenantContext->getCurrentTenant();
        $filters->enable(HoldingIncidentFilter::NAME)->setParameter('viewer_tenant_id', (string) ($viewer?->getId() ?? ''));

        return true;
    }

    /** @return array{value: mixed, type: ?string}|null the armed tenant_id, unquoted */
    private function filterParameter(): ?array
    {
        $filters = $this->entityManager->getFilters();
        if (!$filters->isEnabled(self::FILTER)) {
            return null;
        }
        $stored = (new \ReflectionProperty(SQLFilter::class, 'parameters'))
            ->getValue($filters->getFilter(self::FILTER))['tenant_id'] ?? null;
        if ($stored === null) {
            return null;
        }
        $type = is_object($stored) ? $stored->type : ($stored['type'] ?? null);

        return [
            'value' => is_object($stored) ? $stored->value : ($stored['value'] ?? null),
            'type' => is_string($type) ? $type : null,
        ];
    }
}
