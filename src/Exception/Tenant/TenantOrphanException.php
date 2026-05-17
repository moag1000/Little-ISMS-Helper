<?php

declare(strict_types=1);

namespace App\Exception\Tenant;

use App\Exception\AppException;

/**
 * Thrown when an authenticated user has no tenant assignment.
 *
 * The kernel.exception listener should redirect such users to the
 * "no tenant" landing page (analogous to soa/_no_tenant.html.twig).
 */
final class TenantOrphanException extends AppException
{
    public function __construct(
        private readonly ?int $userId,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf(
                'User %s has no tenant assignment.',
                $userId === null ? 'NULL' : (string) $userId,
            ),
            0,
            $previous,
        );
    }

    public static function forUserId(int $userId): self
    {
        return new self($userId);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
