<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Captures the Doctrine `tenant_filter` — enabled, SUSPENDED or disabled, with
 * its tenant parameter — and puts it back verbatim. Used by
 * {@see EntityManagerRecovery} (around an EntityManager reset) and
 * {@see \App\Service\TenantContext::runAs()} (around a pinned unit of work).
 */
final class TenantFilterState
{
    public const string FILTER = 'tenant_filter';

    /**
     * @return array{state: 'enabled'|'suspended'|'disabled', parameter: ?array{value: mixed, type: mixed}}
     */
    public static function capture(EntityManagerInterface $em): array
    {
        $filters = $em->getFilters();
        if ($filters->isEnabled(self::FILTER)) {
            $state = 'enabled';
            $filter = $filters->getFilter(self::FILTER);
        } elseif ($filters->isSuspended(self::FILTER)) {
            // In ORM 3 a suspended filter is NOT "enabled" — it must not be
            // mistaken for a disabled one.
            $state = 'suspended';
            $filter = $filters->getSuspendedFilters()[self::FILTER];
        } else {
            return ['state' => 'disabled', 'parameter' => null];
        }

        // SQLFilter::getParameter() quotes through the connection, which may
        // be in a failed state here — read the stored value instead.
        $stored = (new \ReflectionProperty(SQLFilter::class, 'parameters'))->getValue($filter)['tenant_id'] ?? null;
        if ($stored === null) {
            return ['state' => $state, 'parameter' => null];
        }

        // ORM 3 stores Query\Filter\Parameter objects; ORM 2 stored arrays.
        return ['state' => $state, 'parameter' => is_object($stored)
            ? ['value' => $stored->value, 'type' => $stored->type]
            : ['value' => $stored['value'] ?? null, 'type' => $stored['type'] ?? null]];
    }

    /**
     * A filter that had no parameter is put back unscoped
     * ({@see TenantFilter::UNSCOPED}), which reads the same as no parameter.
     *
     * @param array{state: 'enabled'|'suspended'|'disabled', parameter: ?array{value: mixed, type: mixed}} $captured
     */
    public static function restore(EntityManagerInterface $em, array $captured): void
    {
        $filters = $em->getFilters();
        if ($captured['state'] === 'disabled') {
            if ($filters->isEnabled(self::FILTER)) {
                $filters->disable(self::FILTER);
            }

            return;
        }
        if (!$filters->has(self::FILTER)) {
            return;
        }

        $tenantFilter = $filters->isSuspended(self::FILTER)
            ? $filters->restore(self::FILTER)
            : $filters->enable(self::FILTER);
        if ($captured['parameter'] !== null) {
            $type = $captured['parameter']['type'];
            $tenantFilter->setParameter('tenant_id', $captured['parameter']['value'], is_string($type) ? $type : null);
        } else {
            $tenantFilter->setParameter('tenant_id', TenantFilter::UNSCOPED);
        }
        if ($captured['state'] === 'suspended') {
            // Suspended again, with its parameter: the caller's later
            // restore('tenant_filter') brings the tenant scope back.
            $filters->suspend(self::FILTER);
        }
    }

    /**
     * Run $fn with the tenant filter suspended (callers that name their scope
     * explicitly) and restore it afterwards.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function suspended(EntityManagerInterface $em, callable $fn): mixed
    {
        $filters = $em->getFilters();
        $suspend = $filters->isEnabled(self::FILTER);
        if ($suspend) {
            $filters->suspend(self::FILTER);
        }
        try {
            return $fn();
        } finally {
            if ($suspend) {
                $filters->restore(self::FILTER);
            }
        }
    }
}
