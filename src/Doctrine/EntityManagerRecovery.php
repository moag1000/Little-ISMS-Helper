<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one place that recovers a closed EntityManager (a failed flush closes
 * it). Every caller that needs a usable manager afterwards goes through
 * {@see self::reset()} — never through ManagerRegistry::resetManager()
 * directly.
 *
 * `resetManager()` gives the same EntityManager object a FRESH state:
 *  - its FilterCollection is rebuilt — `tenant_filter` is enabled from
 *    config but its `tenant_id` parameter is gone, so every later query of
 *    the request / job would read across ALL organisations;
 *  - entities loaded before are no longer managed — TenantContext's cached
 *    Tenant, which AuditLogger puts on every audit row, would make the next
 *    audit flush fail ("new entity found through relationship") and the
 *    non-strict logger would drop every later audit row silently.
 *
 * So the filter state is captured BEFORE the reset and restored after it,
 * and TenantContext is restored onto the new unit of work (its tenant is
 * re-loaded by id; an uninitialised context is reset so it initialises
 * lazily again). AuditLogger's other inputs (the security user's name and
 * roles) are read as plain values, not as managed entities.
 *
 * Not final — command tests mock it.
 */
readonly class EntityManagerRecovery
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Reset the default EntityManager if it is closed; no-op otherwise.
     *
     * @return bool true when a reset happened
     */
    public function resetIfClosed(): bool
    {
        $em = $this->managerRegistry->getManager();
        if ($em instanceof EntityManagerInterface && $em->isOpen()) {
            return false;
        }
        $this->reset();

        return true;
    }

    /**
     * Reset the default EntityManager and restore tenant scope + context.
     */
    public function reset(): void
    {
        $filter = $this->captureTenantFilter();
        $context = $this->tenantContext->snapshot();

        $this->managerRegistry->resetManager();

        // TenantContext first: re-loading the tenant also re-arms the filter
        // (TenantContext::syncDoctrineFilter); the captured filter value is
        // then restored verbatim, so the scope is exactly what it was.
        if ($context['tenantId'] !== null) {
            $this->tenantContext->setCurrentTenantById($context['tenantId']);
        } elseif ($context['noOrganisation']) {
            $this->tenantContext->setNoOrganisation();
        } else {
            $this->tenantContext->reset();
        }

        $this->restoreTenantFilter($filter);
    }

    /**
     * @return array{enabled: bool, parameter: ?array{value: mixed, type: mixed}}
     */
    private function captureTenantFilter(): array
    {
        $em = $this->managerRegistry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            return ['enabled' => false, 'parameter' => null];
        }
        $filters = $em->getFilters();
        if (!$filters->isEnabled('tenant_filter')) {
            return ['enabled' => false, 'parameter' => null];
        }

        // SQLFilter::getParameter() quotes through the connection, which may
        // be in a failed state here — read the stored value instead.
        $stored = (new \ReflectionProperty(SQLFilter::class, 'parameters'))
            ->getValue($filters->getFilter('tenant_filter'))['tenant_id'] ?? null;
        if ($stored === null) {
            return ['enabled' => true, 'parameter' => null];
        }

        // ORM 3 stores Query\Filter\Parameter objects; ORM 2 stored arrays.
        return ['enabled' => true, 'parameter' => is_object($stored)
            ? ['value' => $stored->value, 'type' => $stored->type]
            : ['value' => $stored['value'] ?? null, 'type' => $stored['type'] ?? null]];
    }

    /**
     * @param array{enabled: bool, parameter: ?array{value: mixed, type: mixed}} $filter
     */
    private function restoreTenantFilter(array $filter): void
    {
        $em = $this->managerRegistry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }
        $filters = $em->getFilters();
        if (!$filter['enabled']) {
            if ($filters->isEnabled('tenant_filter')) {
                $filters->disable('tenant_filter');
            }

            return;
        }
        $tenantFilter = $filters->enable('tenant_filter');
        if ($filter['parameter'] !== null) {
            $type = $filter['parameter']['type'];
            $tenantFilter->setParameter('tenant_id', $filter['parameter']['value'], is_string($type) ? $type : null);
        }
    }
}
