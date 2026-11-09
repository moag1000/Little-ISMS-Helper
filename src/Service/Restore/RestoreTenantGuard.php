<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\RoleNames;
use App\Service\Upload\StoredFilePath;
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
 *  - audit-log rows are evidence: never rewritten, never added, never cleared;
 *  - no privileged role (RoleNames::PRIVILEGED: super admin, impersonation, holding
 *    roles) is granted, neither in User.roles nor through a custom role, and an
 *    existing super admin is never touched;
 *  - credentials and identity links (password hash, SSO subject ids) and the
 *    organisation's place in the instance (parent, holding flag, active state,
 *    Azure tenant, API quota) keep their live values — a file cannot set them;
 *  - branding HTML (TenantBranding header/footer, printed unescaped into policy PDFs)
 *    keeps its live value;
 *  - stored file paths (StoredFilePath::FIELDS) of the wrong shape ('../../.env.local',
 *    absolute paths, a logo outside uploads/tenants/) are refused in every restore, and an
 *    organisation restore refuses a path another organisation's record uses — the file
 *    would otherwise be read, zipped into the next backup or deleted for this one (S19).
 *
 * A restore without scope (SUPER_ADMIN, instance-wide) is not restricted, except that it
 * takes no stored file path of the wrong shape either.
 */
final class RestoreTenantGuard
{
    /** Option key under which RestoreService passes the scope to the writer. */
    public const string OPTION_SCOPE = '_tenant_scope_ids';

    /** Records that are evidence: an existing row is never overwritten by a restore. */
    private const array IMMUTABLE_ENTITIES = ['AuditLog'];

    /**
     * Fields an organisation restore never takes from the file: an existing record keeps
     * its live value, a new record its default. Credentials and identity links would let
     * a crafted file log in as someone (or bind an SSO subject to another account); the
     * Tenant fields decide what the organisation is in the instance, which only the
     * instance operator sets.
     */
    private const array PROTECTED_FIELDS = [
        'User' => ['password', 'authProvider', 'azureObjectId', 'azureTenantId', 'azureMetadata', 'ssoExternalId'],
        'Tenant' => ['isActive', 'status', 'isCorporateParent', 'azureTenantId', 'apiRateLimitPerMinute', 'ssoEnforced'],
        'TenantBranding' => ['headerHtml', 'footerHtml'],
    ];

    /** Key under which the writer receives the path fields refused for a row. */
    public const string REJECTED_PATHS_KEY = '__rejected_path_fields__';

    /** Associations an organisation restore never takes from the file (see PROTECTED_FIELDS). */
    private const array PROTECTED_ASSOCIATIONS = [
        'Tenant' => ['parent'],
    ];

    /** @var array<string, array<int|string, int|null>> class → id → tenant id (null = no tenant) */
    private array $tenantOfCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Stored file paths of a row that the restore must not take from the file: wrong shape
     * (any restore), or used by a record outside the scope (organisation restore). The
     * writer leaves these fields alone — an existing record keeps its live value, a new
     * one stays without file.
     *
     * @param mixed[] $data
     * @param int[]   $scopeIds
     * @return list<string> field names
     */
    public function rejectedPathFields(string $entityClass, array $data, array $scopeIds): array
    {
        $rejected = [];
        foreach (StoredFilePath::FIELDS as $column => $kind) {
            [$class, $field] = explode('::', $column, 2);
            if ($class !== $entityClass || !array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value !== null && !is_string($value)) {
                $rejected[] = $field;
                continue;
            }
            if (!StoredFilePath::isValid($kind, $value)
                || ($scopeIds !== [] && $value !== null && $value !== '' && $this->pathUsedOutsideScope($kind, $value, $scopeIds))
            ) {
                $rejected[] = $field;
            }
        }

        return $rejected;
    }

    /**
     * Whether a record outside the scope stores this value in a column of the same kind.
     * Plain SQL: the Doctrine tenant filter would hide exactly those rows.
     *
     * @param int[] $scopeIds
     */
    private function pathUsedOutsideScope(string $kind, string $value, array $scopeIds): bool
    {
        $connection = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        // Same file, spelled with or without the leading slash.
        $variants = array_values(array_unique([$value, ltrim($value, '/'), '/' . ltrim($value, '/')]));
        foreach (StoredFilePath::FIELDS as $column => $otherKind) {
            if ($otherKind !== $kind) {
                continue;
            }
            [$class, $field] = explode('::', $column, 2);
            $meta = $this->entityManager->getClassMetadata($class);
            $owner = $class === Tenant::class
                ? $meta->getSingleIdentifierColumnName()
                : ($meta->hasAssociation('tenant') ? $meta->getSingleAssociationJoinColumnName('tenant') : null);
            $ownerClause = $owner === null ? '1 = 1' : sprintf('(%1$s IS NULL OR %1$s NOT IN (%2$s))', $owner, $placeholders);
            $sql = sprintf(
                'SELECT 1 FROM %s WHERE %s IN (%s) AND %s',
                $meta->getTableName(),
                $meta->getColumnName($field),
                implode(',', array_fill(0, count($variants), '?')),
                $ownerClause,
            );
            $params = $owner === null ? $variants : [...$variants, ...$scopeIds];
            if ($connection->fetchOne($sql, $params) !== false) {
                return true;
            }
        }

        return false;
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
        if (self::isEvidence($entityName)) {
            return false;
        }

        return $entityName === 'Tenant' || $this->hasTenantField($entityClass);
    }

    /**
     * Records that are evidence (the audit trail). A scoped restore neither adds, rewrites
     * nor clears them: added rows would be forged evidence, cleared rows lost evidence.
     */
    public static function isEvidence(string $entityName): bool
    {
        return in_array($entityName, self::IMMUTABLE_ENTITIES, true);
    }

    /**
     * Whether a scalar field is kept at its live value in a scoped restore.
     *
     * @param int[] $scopeIds
     */
    public static function isProtectedField(string $entityName, string $fieldName, array $scopeIds): bool
    {
        return $scopeIds !== [] && in_array($fieldName, self::PROTECTED_FIELDS[$entityName] ?? [], true);
    }

    /**
     * Whether an association keeps its live value in a scoped restore.
     *
     * @param int[] $scopeIds
     */
    public static function isProtectedAssociation(string $entityName, string $field, array $scopeIds): bool
    {
        return $scopeIds !== [] && in_array($field, self::PROTECTED_ASSOCIATIONS[$entityName] ?? [], true);
    }

    /**
     * Whether a link $entityName.$field → $targetClass#$targetId may be restored: the
     * target lies in scope (mayReference), the association is not protected and a custom
     * role does not carry a privileged role name.
     *
     * @param int[] $scopeIds
     */
    public function mayLink(string $entityName, string $field, string $targetClass, int|string $targetId, array $scopeIds): bool
    {
        if ($scopeIds === []) {
            return true;
        }
        if (self::isProtectedAssociation($entityName, $field, $scopeIds)) {
            return false;
        }
        if ($targetClass === Role::class) {
            $name = $this->entityManager->getConnection()->fetchOne(
                sprintf('SELECT name FROM %s WHERE id = ?', $this->entityManager->getClassMetadata(Role::class)->getTableName()),
                [$targetId],
            );
            if ($name === false || RoleNames::isPrivileged(is_string($name) ? $name : null)) {
                return false;
            }
        }

        return $this->mayReference($targetClass, $targetId, $scopeIds);
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
        // An account with a privileged role is the instance operator's (or the holding's):
        // a restore could otherwise change its e-mail and take it over via password reset.
        if ($existing instanceof User && array_filter($existing->getRoles(), RoleNames::isPrivileged(...)) !== []) {
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
            static fn (mixed $role): bool => is_string($role) && !RoleNames::isPrivileged($role),
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
