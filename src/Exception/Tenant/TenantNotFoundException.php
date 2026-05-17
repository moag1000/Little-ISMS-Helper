<?php

declare(strict_types=1);

namespace App\Exception\Tenant;

use App\Exception\AppException;

/**
 * Thrown by repositories when a tenant lookup fails.
 */
final class TenantNotFoundException extends AppException
{
    public function __construct(
        private readonly int|string|null $tenantIdentifier,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf(
                'Tenant not found for identifier "%s".',
                $tenantIdentifier === null ? 'NULL' : (string) $tenantIdentifier,
            ),
            0,
            $previous,
        );
    }

    public static function byId(int $id): self
    {
        return new self($id);
    }

    public static function bySlug(string $slug): self
    {
        return new self($slug, \sprintf('Tenant not found for slug "%s".', $slug));
    }

    public function getTenantIdentifier(): int|string|null
    {
        return $this->tenantIdentifier;
    }
}
