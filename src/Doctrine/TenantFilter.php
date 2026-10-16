<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine Tenant Filter
 *
 * Automatically filters all queries to only return entities
 * that belong to the current tenant. Entities must have a
 * 'tenant' property with a ManyToOne relation to Tenant entity.
 * Entities implementing {@see SharedTenantRowsInterface} additionally
 * expose their tenant-less (instance-wide shared) rows.
 *
 * Usage in config/packages/doctrine.yaml:
 * doctrine:
 *     orm:
 *         filters:
 *             tenant_filter:
 *                 class: App\Doctrine\TenantFilter
 *                 enabled: true
 */
class TenantFilter extends SQLFilter
{
    /**
     * Add tenant filter condition to SQL query
     *
     * @throws MappingException
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Skip filtering for Tenant entity itself and User entity
        if ($targetEntity->getReflectionClass()->getName() === Tenant::class) {
            return '';
        }

        if ($targetEntity->getReflectionClass()->getName() === User::class) {
            return '';
        }

        // Check if entity has a 'tenant' association
        if (!$targetEntity->hasAssociation('tenant')) {
            return '';
        }

        // Get the tenant ID parameter. If never set (background jobs, CLI,
        // tests), skip filtering entirely — SQLFilter::getParameter throws
        // InvalidArgumentException when the parameter is absent.
        try {
            $tenantId = $this->getParameter('tenant_id');
        } catch (\InvalidArgumentException) {
            return '';
        }

        // SQLFilter::getParameter quotes the value via the connection's
        // quote() method, so the literal string sentinel from the subscriber
        // arrives here as `'null'` (single-quoted), not `null`. Strip outer
        // quoting before the sentinel check, otherwise the filter generates
        // `tenant_id = 'null'` which never matches integer columns and
        // silently hides every row from authenticated-but-no-tenant users.
        $rawValue = trim($tenantId, "'\"");
        if ($rawValue === 'null' || $rawValue === '') {
            return '';
        }

        // Add tenant filter constraint.
        // Doctrine 3.x → 4.0: ArrayAccess on AssociationMapping is deprecated.
        // Use property access if object, else array fallback (Doctrine 2.x compat).
        $mapping = $targetEntity->getAssociationMapping('tenant');
        $fieldName = is_array($mapping) ? $mapping['fieldName'] : $mapping->fieldName;

        $column = sprintf('%s.%s', $targetTableAlias, $fieldName . '_id');

        if ($targetEntity->getReflectionClass()->implementsInterface(SharedTenantRowsInterface::class)) {
            // Own rows plus instance-wide shared rows (tenant = NULL).
            return sprintf('(%1$s = %2$s OR %1$s IS NULL)', $column, $tenantId);
        }

        return sprintf('%s = %s', $column, $tenantId);
    }
}
