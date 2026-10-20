<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Marker for entities whose `tenant` association is nullable on purpose:
 * a row with tenant = NULL is shared instance-wide (derived from global
 * catalogue data), a row with a tenant belongs to that organisation only.
 *
 * {@see TenantFilter} lets an organisation see its own rows plus the shared
 * ones (`tenant_id = :current OR tenant_id IS NULL`) instead of hiding every
 * shared row behind a strict equality.
 */
interface SharedTenantRowsInterface
{
}
