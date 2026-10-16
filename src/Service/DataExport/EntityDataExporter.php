<?php

declare(strict_types=1);

namespace App\Service\DataExport;

use App\Entity\ApiToken;
use App\Entity\MfaToken;
use App\Entity\PushSubscription;
use App\Entity\Tenant;
use App\Entity\UserSession;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use App\Util\CsvSanitizer;

/**
 * Single source of truth for the generic "export entity tables" admin feature
 * (sync {@see \App\Controller\AdminBackupController::exportExecute()} and async
 * {@see \App\Job\ExportDataJob}).
 *
 * Security rules, applied identically on both paths:
 *
 *  1. Credential / session tables are never exportable
 *     ({@see self::DENIED_ENTITY_CLASSES}) — MFA secrets and backup codes,
 *     API token hashes, push-subscription keys, session records.
 *  2. Secret-bearing fields are stripped from every row, whatever the class
 *     ({@see self::DENIED_FIELD_NAMES} + {@see self::SENSITIVE_FIELD_PATTERN}):
 *     password hashes, encrypted secrets, tokens, …
 *  3. With a tenant scope (the normal case — the caller's organisation) only
 *     classes that can be scoped to a tenant are exportable, and every query
 *     carries the tenant criterion explicitly; the Doctrine tenant filter is
 *     not relied upon (it skips User and int-`tenantId` entities and is
 *     unarmed outside the HTTP request). Global tables without a tenant
 *     (catalogues, settings) are only exportable instance-wide.
 *  4. A null scope means instance-wide and is only ever passed for a
 *     SUPER_ADMIN without an organisation ({@see \App\Service\Job\JobTenantScope}).
 */
final readonly class EntityDataExporter
{
    /**
     * Entity classes that hold credentials or session state and are never
     * exported, not even instance-wide.
     *
     * @var list<class-string>
     */
    public const array DENIED_ENTITY_CLASSES = [
        MfaToken::class,
        UserSession::class,
        ApiToken::class,
        PushSubscription::class,
    ];

    /**
     * Field names (case-insensitive) that are always stripped. The pattern
     * below catches the generic cases; this list names fields that do not
     * match it but still carry secrets or identity-provider payloads.
     *
     * @var list<string>
     */
    public const array DENIED_FIELD_NAMES = [
        'password',
        'plainPassword',
        'backupCodes',
        'azureMetadata',
        'publicPostureToken',
    ];

    /** Field names matching this pattern are always stripped. */
    public const string SENSITIVE_FIELD_PATTERN = '/password|secret|token|backup_?codes|encrypted|api_?key|private_?key|credential|session_?id/i';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Entity classes the given scope may export, for the selection UI.
     *
     * @return list<array{name: string, class: class-string, table: string}>
     */
    public function listExportableEntities(?Tenant $scope): array
    {
        $entities = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass || !$this->isExportableMeta($meta, $scope)) {
                continue;
            }
            $className = $meta->getName();
            $entities[] = [
                'name' => substr($className, (int) strrpos($className, '\\') + 1),
                'class' => $className,
                'table' => $meta->getTableName(),
            ];
        }

        usort($entities, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $entities;
    }

    public function isExportable(string $className, ?Tenant $scope): bool
    {
        $meta = $this->metadataFor($className);

        return $meta !== null && $this->isExportableMeta($meta, $scope);
    }

    /**
     * Export the given classes. Classes that are unknown or not exportable
     * for the scope are skipped silently (the UI only offers exportable ones).
     *
     * @param iterable<mixed>                                  $classNames
     * @param (callable(int $done, int $total, string $shortName): void)|null $onProgress
     *
     * @return array<string, list<array<string, mixed>>> short class name => rows
     */
    public function export(iterable $classNames, ?Tenant $scope, ?callable $onProgress = null): array
    {
        $classNames = array_values(array_filter(
            is_array($classNames) ? $classNames : iterator_to_array($classNames, false),
            static fn(mixed $c): bool => is_string($c),
        ));
        $total = count($classNames);
        $exportData = [];

        foreach ($classNames as $index => $className) {
            $meta = $this->metadataFor($className);
            if ($meta === null || !$this->isExportableMeta($meta, $scope)) {
                continue;
            }

            $shortName = substr($meta->getName(), (int) strrpos($meta->getName(), '\\') + 1);
            if ($onProgress !== null) {
                $onProgress($index + 1, $total, $shortName);
            }

            $exportData[$shortName] = array_map(
                fn(object $entity): array => $this->entityToArray($meta, $entity),
                $this->loadRows($meta, $scope),
            );
        }

        return $exportData;
    }

    /**
     * @param ClassMetadata<object> $meta
     */
    private function isExportableMeta(ClassMetadata $meta, ?Tenant $scope): bool
    {
        if (!str_starts_with($meta->getName(), 'App\\Entity\\')) {
            return false;
        }
        if (in_array($meta->getName(), self::DENIED_ENTITY_CLASSES, true)) {
            return false;
        }

        return $scope === null || $this->tenantCriterion($meta) !== null;
    }

    /**
     * How a class is scoped to a tenant, or null when it cannot be.
     *
     * @param ClassMetadata<object> $meta
     *
     * @return 'self'|'association'|'column'|null
     */
    private function tenantCriterion(ClassMetadata $meta): ?string
    {
        if ($meta->getName() === Tenant::class) {
            return 'self';
        }
        if ($meta->hasAssociation('tenant') && $meta->getAssociationTargetClass('tenant') === Tenant::class) {
            return 'association';
        }
        if ($meta->hasField('tenantId')) {
            return 'column';
        }

        return null;
    }

    /**
     * @param ClassMetadata<object> $meta
     *
     * @return list<object>
     */
    private function loadRows(ClassMetadata $meta, ?Tenant $scope): array
    {
        $qb = $this->entityManager->createQueryBuilder()->select('e')->from($meta->getName(), 'e');

        if ($scope !== null) {
            match ($this->tenantCriterion($meta)) {
                'self' => $qb->andWhere('e.id = :tenantId')->setParameter('tenantId', $scope->getId()),
                'association' => $qb->andWhere('e.tenant = :tenant')->setParameter('tenant', $scope),
                'column' => $qb->andWhere('e.tenantId = :tenantId')->setParameter('tenantId', $scope->getId()),
                // isExportableMeta() already rejected unscopable classes.
                // @intentional-assertion: unreachable — guarded by isExportableMeta()
                default => throw new \LogicException(sprintf('%s cannot be tenant-scoped.', $meta->getName())),
            };
        }

        /** @var list<object> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Scalar fields only (associations skipped), DateTime → 'Y-m-d H:i:s',
     * secret-bearing fields removed.
     *
     * @param ClassMetadata<object> $meta
     *
     * @return array<string, mixed>
     */
    private function entityToArray(ClassMetadata $meta, object $entity): array
    {
        $row = [];
        foreach ($meta->getFieldNames() as $field) {
            if (self::isSensitiveField($field)) {
                continue;
            }
            try {
                $value = $meta->getFieldValue($entity, $field);
                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                } elseif ($value instanceof \BackedEnum) {
                    $value = $value->value;
                } elseif ($value instanceof \UnitEnum) {
                    $value = $value->name;
                }
                $row[$field] = $value;
            } catch (\Throwable) {
                // Skip inaccessible fields silently.
            }
        }

        return $row;
    }

    /**
     * One CSV cell: arrays (JSON columns) are JSON-encoded instead of
     * triggering "Array to string conversion", then CSV-injection sanitised.
     */
    public static function csvCell(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        return CsvSanitizer::sanitize($value);
    }

    public static function isSensitiveField(string $field): bool
    {
        foreach (self::DENIED_FIELD_NAMES as $denied) {
            if (strcasecmp($denied, $field) === 0) {
                return true;
            }
        }

        return preg_match(self::SENSITIVE_FIELD_PATTERN, $field) === 1;
    }

    /**
     * @return ClassMetadata<object>|null
     */
    private function metadataFor(string $className): ?ClassMetadata
    {
        if (!str_starts_with($className, 'App\\Entity\\') || !class_exists($className)) {
            return null;
        }
        try {
            return $this->entityManager->getClassMetadata($className);
        } catch (\Throwable) {
            return null;
        }
    }
}
