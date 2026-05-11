<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared base for all entity import mappers.
 *
 * Provides:
 *  - Tenant-scoping helpers
 *  - Type-cast utilities (int, float, bool, enum, datetime)
 *
 * Concrete mappers extend this class and inject additional repositories as
 * needed. The EntityManagerInterface is kept protected so sub-classes can
 * call getRepository() without re-declaring a constructor argument.
 */
abstract class AbstractEntityMapper implements EntityMapperInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
    ) {
    }

    // ─── Tenant helpers ──────────────────────────────────────────────────────

    /**
     * Return the Tenant's primary key, or null for global records.
     */
    protected function resolveTenantId(?Tenant $tenant): ?int
    {
        return $tenant?->getId();
    }

    /**
     * Assert that a mapped entity belongs to the expected tenant.
     *
     * @throws \RuntimeException when ownership mismatch is detected
     */
    protected function validateTenantOwnership(object $entity, Tenant $expectedTenant): void
    {
        if (!method_exists($entity, 'getTenant')) {
            return;
        }

        $owningTenant = $entity->getTenant();
        if ($owningTenant !== null && $owningTenant->getId() !== $expectedTenant->getId()) {
            throw new \RuntimeException(sprintf(
                'Tenant ownership mismatch: entity belongs to tenant %d, expected %d.',
                (int) $owningTenant->getId(),
                (int) $expectedTenant->getId(),
            ));
        }
    }

    // ─── Type-cast helpers ───────────────────────────────────────────────────

    /**
     * Cast a value to int, or return null when the value is empty/null.
     */
    protected function castInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Cast a value to float, or return null when the value is empty/null.
     */
    protected function castFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Cast a value to bool.
     * Truthy strings: '1', 'true', 'yes', 'ja', 'x'.
     */
    protected function castBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return false;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'ja', 'x'],
            strict: true,
        );
    }

    /**
     * Validate that $value belongs to the allowed set and return it
     * (lowercased and trimmed). Returns $default when invalid or empty.
     *
     * @param list<string> $allowedValues
     */
    protected function castEnum(mixed $value, array $allowedValues, ?string $default = null): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowedValues, strict: true) ? $normalized : $default;
    }

    /**
     * Parse a date/datetime string into a DateTimeImmutable.
     * Accepts ISO-8601 and common European formats (d.m.Y, d/m/Y).
     *
     * Returns null when $value is empty or unparseable.
     */
    protected function castDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $str = trim((string) $value);

        // Try ISO-8601 first
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $str)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $str)
            ?: \DateTimeImmutable::createFromFormat('d.m.Y', $str)
            ?: \DateTimeImmutable::createFromFormat('d/m/Y', $str);

        return $dt instanceof \DateTimeImmutable ? $dt : null;
    }

    /**
     * Normalise a column-name key for case-insensitive, whitespace-agnostic lookup.
     */
    protected function normaliseKey(string $key): string
    {
        return strtolower(preg_replace('/\s+/', '_', trim($key)) ?? $key);
    }

    /**
     * Resolve a raw row key taking optional column-mapping overrides into account.
     *
     * @param array<string, mixed>       $row
     * @param array<string, string>|null $columnMapping  mapping from spreadsheet header → field name
     */
    protected function resolveField(array $row, string $fieldName, ?array $columnMapping): mixed
    {
        // If caller provided an explicit mapping, prefer that
        if ($columnMapping !== null) {
            foreach ($columnMapping as $header => $mappedField) {
                if ($mappedField === $fieldName && array_key_exists($header, $row)) {
                    return $row[$header];
                }
            }
        }

        // Fall back to direct key lookup (case-insensitive)
        $normField = $this->normaliseKey($fieldName);
        foreach ($row as $key => $value) {
            if ($this->normaliseKey($key) === $normField) {
                return $value;
            }
        }

        return null;
    }
}
