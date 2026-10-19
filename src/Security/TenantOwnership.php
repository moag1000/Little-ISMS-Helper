<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;
use App\Service\TenantContext;

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
     * Whether $target is $root itself or lies anywhere below it in the
     * holding tree (never up, never sideways).
     *
     * The one tree comparison: {@see TenantContext::canAccessTenant()}
     * (organisation administration, `TenantScopedAdminVoter`, bulk context
     * scope) and the holding read of {@see Voter\HoldingTreeAccessTrait}
     * both use it. Ancestors are compared like {@see isSameTenant()} (identity
     * or id), so a Doctrine proxy or detached copy of $root still matches.
     * Fails closed on a missing tenant.
     */
    public static function isSameOrDescendant(?Tenant $root, ?Tenant $target): bool
    {
        if (!$root instanceof Tenant) {
            return false;
        }
        $seen = []; // guards against a corrupt parent cycle
        for ($node = $target; $node instanceof Tenant && !isset($seen[spl_object_id($node)]); $node = $node->getParent()) {
            if (self::isSameTenant($node, $root)) {
                return true;
            }
            $seen[spl_object_id($node)] = true;
        }

        return false;
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

    /**
     * Scope check for bulk operations, resolved from the tenant the actor is
     * actually acting in ({@see TenantContext::getCurrentTenant()}), NOT from
     * the user's own tenant column — a ROLE_SUPER_ADMIN has none but views a
     * tenant through the context.
     *
     * Own tenant or any descendant ({@see TenantContext::canAccessTenant()}).
     * Without any tenant context only ROLE_SUPER_ADMIN is in scope.
     * Fails closed on entities without a tenant. The single rule for bulk
     * delete and bulk status change.
     */
    public static function isInContextScope(object $entity, ?TenantContext $tenantContext, bool|\Closure $actorIsSuperAdmin): bool
    {
        $acting = $tenantContext?->getCurrentTenant();
        if (!$acting instanceof Tenant) {
            return $actorIsSuperAdmin instanceof \Closure ? $actorIsSuperAdmin() : $actorIsSuperAdmin;
        }
        if (!method_exists($entity, 'getTenant')) {
            return false;
        }
        $entityTenant = $entity->getTenant();

        return $entityTenant instanceof Tenant && $tenantContext->canAccessTenant($entityTenant);
    }
}
