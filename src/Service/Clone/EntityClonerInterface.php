<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\Tenant;

/**
 * Shared contract for entity-cloning services (C4-C1 "Klon-Funktionen").
 *
 * Implementations clone a single entity inside a tenant scope and persist the
 * clone (caller is expected to flush). The clone:
 *
 *   - Preserves the "template" data (titles, descriptions, configuration,
 *     M2M references to master data) so the user can re-run the same
 *     content with minimal edits.
 *   - Resets lifecycle / workflow state to the entity's initial marking
 *     (status = draft / planned / new) and clears completion / approval
 *     fields so the clone starts fresh.
 *   - Omits cascade children whose history must not be duplicated
 *     (findings, exercise logs, version history, audit-log entries).
 *
 * Each implementation is intentionally entity-specific because the field
 * lists, M2M relations, and lifecycle nuances diverge — a generic
 * "deep-clone everything" service would produce broken audit trails.
 */
interface EntityClonerInterface
{
    /**
     * Clone a source entity within (or into) a tenant.
     *
     * @param object       $source        The entity to clone (concrete type per implementor)
     * @param Tenant|null  $targetTenant  Override target tenant; defaults to source tenant
     * @param string|null  $titleOverride Optional new title; defaults to "<original> (Kopie)"
     *
     * @return object The persisted clone (caller is expected to flush)
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): object;

    /**
     * Returns the FQCN of the entity this cloner handles. Used by the
     * controller layer to route a request to the correct implementation.
     */
    public function supportsEntity(): string;
}
