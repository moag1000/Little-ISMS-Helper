<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The related User / Person (owner, assignee, approver, participant ...) must
 * belong to the same tenant as the entity carrying it. `User` is not covered
 * by the Doctrine TenantFilter, so API writes or tampered form posts could
 * otherwise link a user of another organisation.
 *
 * Applies to a single relation (ManyToOne) and to collections (ManyToMany /
 * arrays): every element is checked.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class SameTenantOwner extends Constraint
{
    public string $message = 'tenant.validation.owner_other_tenant';
}
