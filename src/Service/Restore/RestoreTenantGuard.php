<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps an organisation restore inside the organisations it is restored for.
 *
 * An uploaded backup is data from outside: its ids, its tenant_id values and its
 * metadata (tenant_scope, SHA256 seal) can all be written by hand. A restore with
 * a tenant scope therefore never trusts them and checks every write against the
 * database instead:
 *
 *  - rows of entities without a tenant association (roles, permissions, system
 *    settings, MFA tokens, sessions, …) are instance data and never restored;
 *  - rows of tenant entities without a tenant_id are dropped;
 *  - a row that matches an existing record (by id or unique field) of another
 *    organisation is skipped, never updated, moved or deleted;
 *  - references (ManyToOne and ManyToMany) to records of other organisations
 *    are dropped;
 *  - existing audit-log rows are never rewritten;
 *  - ROLE_SUPER_ADMIN is never granted, and an existing super admin is never touched.
 *
 * A restore without scope (SUPER_ADMIN, instance-wide) is not restricted.
 */
final class RestoreTenantGuard
{
    /** Option key under which RestoreService passes the scope to the writer. */
    public const string OPTION_SCOPE = '_tenant_scope_ids';

    /** Records that are evidence: an existing row is never overwritten by a restore. */
    private const array IMMUTABLE_ENTITIES = ['AuditLog'];

    /** Roles an organisation restore may never grant. */
    private const array INSTANCE_ROLES = ['ROLE_SUPER_ADMIN'];

    /** @var array<string, array<int|string, int|null>> class → id → tenant id (null = no tenant) */
    private array $tenantOfCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param mixed[] $options
     * @return int[]
     */
    public static function scopeFromOptions(array $options): array
    {
        $scope = $options[self::OPTION_SCOPE] ?? [];

        return is_array($scope) ? array_values(array_map('intval', $scope)) : [];
    }

    public function hasTenantField(string $entityClass): bool
    {
        if (!class_exists($entityClass)) {
            return false;
        }

        return $this->entityManager->getClassMetadata($entityClass)->hasAssociation('tenant');
    }

    /**
     * Whether rows of this entity may be restored at all in a scoped restore.
     * The Tenant rows are filtered by id and pinned elsewhere.
     */
    public function isRestorableInScope(string $entityName, string $entityClass): bool
    {
        return $entityName === 'Tenant' || $this->hasTenantField($entityClass);
    }

    /**
     * Whether an existing record found for an incoming row may be written by this restore.
     *
     * @param int[] $scopeIds
     */
    public function mayOverwrite(string $entityName, object $existing, array $scopeIds): bool
    {
        if ($scopeIds === []) {
            return true;
        }
        if (in_array($entityName, self::IMMUTABLE_ENTITIES, true)) {
            return false;
        }
        if ($existing instanceof User && in_array('ROLE_SUPER_ADMIN', $existing->getRoles(), true)) {
            return false;
        }
        if ($existing instanceof Tenant) {
            return in_array((int) $existing->getId(), $scopeIds, true);
        }
        if (!method_exists($existing, 'getTenant')) {
            return false;
        }
        $tenant = $existing->getTenant();

        return $tenant !== null && in_array((int) $tenant->getId(), $scopeIds, true);
    }

    /**
     * Whether a reference to $targetClass#$id may be restored: the target is global
     * (no tenant association, or a shared row with tenant NULL) or belongs to the scope.
     * A target that does not exist yet is allowed — it can only be created inside the
     * scope by the same restore.
     *
     * @param int[] $scopeIds
     */
    public function mayReference(string $targetClass, int|string $id, array $scopeIds): bool
    {
        if ($scopeIds === [] || !$this->hasTenantField($targetClass)) {
            return true;
        }
        $tenantId = $this->tenantOf($targetClass, $id);

        return $tenantId === null || in_array($tenantId, $scopeIds, true);
    }

    /**
     * Removes roles an organisation restore may not grant.
     *
     * @param mixed[] $data
     * @param int[]   $scopeIds
     * @return mixed[]
     */
    public function sanitizeUserRow(array $data, array $scopeIds): array
    {
        if ($scopeIds === [] || !isset($data['roles']) || !is_array($data['roles'])) {
            return $data;
        }
        $data['roles'] = array_values(array_filter(
            $data['roles'],
            static fn (mixed $role): bool => !in_array($role, self::INSTANCE_ROLES, true),
        ));

        return $data;
    }

    private function tenantOf(string $entityClass, int|string $id): ?int
    {
        if (array_key_exists($id, $this->tenantOfCache[$entityClass] ?? [])) {
            return $this->tenantOfCache[$entityClass][$id];
        }
        // Plain SQL on purpose: the Doctrine tenant filter would hide another
        // organisation's row and make it look like a missing (allowed) target.
        $meta = $this->entityManager->getClassMetadata($entityClass);
        $tenantColumn = $meta->getSingleAssociationJoinColumnName('tenant');
        $idColumn = $meta->getSingleIdentifierColumnName();
        $value = $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT %s FROM %s WHERE %s = ?', $tenantColumn, $meta->getTableName(), $idColumn),
            [$id],
        );
        $tenantId = $value === false || $value === null ? null : (int) $value;

        return $this->tenantOfCache[$entityClass][$id] = $tenantId;
    }
}
