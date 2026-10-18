<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;

/**
 * The one tenant-ownership comparison used by voters
 * ({@see Voter\HoldingTreeAccessTrait}), bulk endpoints
 * ({@see \App\Controller\Trait\BulkActionTrait::isInBulkTenantScope()}) and
 * {@see \App\Service\BulkDeleteService}.
 *
 * Fails closed: a missing tenant on either side never matches, and two
 * unsaved (id-less) tenants are never treated as equal. Identity first; the
 * id comparison covers Doctrine proxies.
 */
final class TenantOwnership
{
    public static function isSameTenant(?Tenant $a, ?Tenant $b): bool
    {
        if (!$a instanceof Tenant || !$b instanceof Tenant) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * Whether an actor acting in $actingTenant may process $entity.
     *
     * The entity must belong to the acting tenant. An actor without tenant
     * context matches only when it is ROLE_SUPER_ADMIN (the only
     * instance-wide role) — then every entity is in scope.
     */
    public static function isInScope(object $entity, ?Tenant $actingTenant, bool $actorIsSuperAdmin): bool
    {
        if (!$actingTenant instanceof Tenant) {
            return $actorIsSuperAdmin;
        }
        if (!method_exists($entity, 'getTenant')) {
            return false;
        }
        $entityTenant = $entity->getTenant();

        return $entityTenant instanceof Tenant && self::isSameTenant($entityTenant, $actingTenant);
    }
}
