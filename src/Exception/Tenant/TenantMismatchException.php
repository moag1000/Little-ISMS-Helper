<?php

declare(strict_types=1);

namespace App\Exception\Tenant;

use App\Exception\AppException;

/**
 * Thrown when an entity's tenant_id does not match the current TenantContext.
 *
 * Indicates a cross-tenant data access attempt — a security-relevant event
 * that MUST be logged and translated to HTTP 403 by the kernel.exception
 * listener.
 */
final class TenantMismatchException extends AppException
{
    public function __construct(
        private readonly ?int $entityTenantId,
        private readonly ?int $expectedTenantId,
        private readonly ?string $entityClass = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Tenant mismatch: entity%s belongs to tenant %s but current context is tenant %s.',
                $entityClass ? ' ('.$entityClass.')' : '',
                $entityTenantId === null ? 'NULL' : (string) $entityTenantId,
                $expectedTenantId === null ? 'NULL' : (string) $expectedTenantId,
            ),
            0,
            $previous,
        );
    }

    public static function forEntity(object $entity, ?int $expectedTenantId): self
    {
        $entityTenantId = null;
        if (\method_exists($entity, 'getTenant')) {
            $tenant = $entity->getTenant();
            if (\is_object($tenant) && \method_exists($tenant, 'getId')) {
                $tenantId = $tenant->getId();
                $entityTenantId = \is_int($tenantId) ? $tenantId : null;
            }
        } elseif (\method_exists($entity, 'getTenantId')) {
            $tenantId = $entity->getTenantId();
            $entityTenantId = \is_int($tenantId) ? $tenantId : null;
        }

        return new self($entityTenantId, $expectedTenantId, $entity::class);
    }

    public function getEntityTenantId(): ?int
    {
        return $this->entityTenantId;
    }

    public function getExpectedTenantId(): ?int
    {
        return $this->expectedTenantId;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }
}
