<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * A tenant-owned IdentityProvider must not take the slug of a global
 * provider: slugs are unique per organisation only, and an organisation
 * squatting the operator's slug could otherwise attract that provider's
 * logins (#1059 I-5).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class IdentityProviderSlugAvailable extends Constraint
{
    public string $message = 'sso.slug_used_by_global_provider';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
