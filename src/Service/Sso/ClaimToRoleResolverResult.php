<?php

declare(strict_types=1);

namespace App\Service\Sso;

/** Immutable result of a ClaimToRoleResolver::resolve() call. */
final class ClaimToRoleResolverResult
{
    public function __construct(
        public readonly string $role,
        public readonly bool $matched,
        public readonly string $trace,
        /** @var list<string> */
        public readonly array $assignedPermissions = [],
    ) {
    }
}
