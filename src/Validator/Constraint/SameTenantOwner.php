<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The related User / Person (owner picker) must belong to the same tenant as
 * the entity carrying it. `User` is not covered by the Doctrine TenantFilter,
 * so API writes could otherwise link a user of another organisation.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class SameTenantOwner extends Constraint
{
    public string $message = 'tenant.validation.owner_other_tenant';
}
