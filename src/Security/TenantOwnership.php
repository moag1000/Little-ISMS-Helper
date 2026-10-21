<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;
use App\Service\TenantContext;

/**
 * The tenant-ownership comparisons: same organisation ({@see isSameTenant()},
 * record-level voters via {@see Voter\HoldingTreeAccessTrait}) and own
 * organisation or below ({@see isSameOrDescendant()}, holding read and
 * {@see TenantContext::canAccessTenant()}). Every bulk endpoint
 * ({@see \App\Controller\Trait\BulkActionTrait::isInBulkContextScope()},
 * {@see \App\Service\BulkDeleteService}) uses {@see isInContextScope()};
 * single-record controllers without a voter use {@see isInScope()}.
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
     * (organisation administration, `TenantScopedAdminVoter`) and the holding
     * read of {@see Voter\HoldingTreeAccessTrait} both use it. Bulk
     * operations do NOT — they follow the own-organisation record scope of
     * {@see isInContextScope()}. Ancestors are compared like {@see isSameTenant()} (identity
     * or id), so a Doctrine proxy or detached copy of $root still matches.
     * Fails closed on a missing tenant.
     */
    public static function isSameOrDescendant(?Tenant $root, ?Tenant $target): bool
    {
        if (!$root instanceof Tenant) {
            return false;
        }
        $seen = []; // guards against a corrupt parent cycle
        for (
            $node = $target;
            $node instanceof Tenant && !isset($seen[spl_object_id($node)]);
            $node = $node->getParent()
        ) {
            if (self::isSameTenant($node, $root)) {
                return true;
            }
            $seen[spl_object_id($node)] = true;
        }

        return false;
    }

    /**
     * Strictly below $root in the holding tree ({@see isSameOrDescendant()}
     * without $root itself) — the id-safe replacement for Tenant::isChildOf().
     */
    public static function isDescendant(?Tenant $root, ?Tenant $target): bool
    {
        return self::isSameOrDescendant($root, $target) && !self::isSameTenant($root, $target);
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
     * Scope check for bulk operations (export, assign incl. the assignee,
     * status change, delete) — the documented *record* scope for writes. It
     * matches the write side of the voters
     * ({@see Voter\HoldingTreeAccessTrait::decideTenantScope()}); the
     * voters' holding read of subsidiaries does not apply here:
     *
     *  - ROLE_SUPER_ADMIN is instance-wide, even with a tenant context set.
     *  - Everyone else (ROLE_ADMIN and holding roles included): the entity
     *    must belong to the tenant the actor is acting in
     *    ({@see TenantContext::getCurrentTenant()}, NOT the user's tenant
     *    column — the voters compare the user's tenant). Never subsidiaries:
     *    holding roles only read records one by one, they never
     *    bulk-process them.
     *  - No tenant context, or an entity without a tenant: out of scope.
     */
    public static function isInContextScope(object $entity, ?TenantContext $tenantContext, bool|\Closure $actorIsSuperAdmin): bool
    {
        if ($actorIsSuperAdmin instanceof \Closure ? $actorIsSuperAdmin() : $actorIsSuperAdmin) {
            return true;
        }
        $acting = $tenantContext?->getCurrentTenant();
        if (!$acting instanceof Tenant || !method_exists($entity, 'getTenant')) {
            return false;
        }
        $entityTenant = $entity->getTenant();

        return $entityTenant instanceof Tenant && self::isSameTenant($entityTenant, $acting);
    }
}
