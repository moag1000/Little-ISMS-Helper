<?php

namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine Tenant Filter
 *
 * Automatically filters all queries to only return entities
 * that belong to the current tenant. Entities must have a
 * 'tenant' property with a ManyToOne relation to Tenant entity.
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
     * @param ClassMetadata $targetEntity
     * @param string $targetTableAlias
     * @return string
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        // Skip filtering for Tenant entity itself and User entity
        if ($targetEntity->getReflectionClass()->getName() === 'App\Entity\Tenant') {
            return '';
        }

        if ($targetEntity->getReflectionClass()->getName() === 'App\Entity\User') {
            return '';
        }

        // Check if entity has a 'tenant' association
        if (!$targetEntity->hasAssociation('tenant')) {
            return '';
        }

        // Get the tenant ID parameter
        $tenantId = $this->getParameter('tenant_id');

        // If no tenant ID is set, don't filter (admin mode)
        if ($tenantId === null || $tenantId === 'null') {
            return '';
        }

        // Add tenant filter constraint
        $fieldName = $targetEntity->getAssociationMapping('tenant')['fieldName'];

        return sprintf('%s.%s = %s', $targetTableAlias, $fieldName . '_id', $tenantId);
    }
}
