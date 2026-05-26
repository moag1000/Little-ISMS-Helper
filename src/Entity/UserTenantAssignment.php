<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Multi-Tenant User Assignment — join entity expressing the M:N relation
 * between a {@see User} and the {@see Tenant}(s) they may operate on.
 *
 * Skeleton entity introduced by the design-spec
 * `docs/superpowers/specs/2026-05-26-multi-tenant-user-assignment.md`. The
 * full implementation (migration, repository, service, voter, controller,
 * BC-shim on {@see User::getTenant()}) lands in the follow-up PR-1 — this
 * skeleton exists so the spec has a concrete schema target to reference.
 *
 * The legacy {@see User::$tenant} M:1 association continues to function as
 * the *primary* assignment via a backwards-compat shim. Every existing
 * single-tenant user is backfilled to exactly one row in this table with
 * `is_primary = true`. Additional sister-organisation assignments are added
 * post-deployment via the admin UI.
 *
 * Use-cases unlocked:
 *  - Sister-org CISO with write access to non-hierarchical tenants
 *    (e.g. Tochter A + Tochter C without a common Holding parent).
 *  - Fractional / outsourced DPO contracted by multiple unrelated SMBs.
 *  - Internal-audit pool with time-bounded scope rotation per audit cycle.
 *
 * Audit trail: every grant / revoke / primary-change is logged via
 * `AuditLogger` with action ∈ {tenant_assignment_grant,
 * tenant_assignment_revoke, tenant_primary_change}. Required by
 * ISO 27001 §A.5.16 (Identity Management) + §A.5.18 (Access Rights).
 *
 * @see \App\Entity\User::$tenant — legacy M:1 (kept for BC-shim)
 * @see \App\Service\TenantContext::getAccessibleTenants()
 * @see \App\Security\Voter\HoldingTreeAccessTrait — ROLE_GROUP_CISO precedent
 */
// Spec-only skeleton — the #[ORM\Entity] / #[ORM\Table] activation lands
// in PR-1 together with the migration, repository, service, and voter
// (see docs/superpowers/specs/2026-05-26-multi-tenant-user-assignment.md
// §4 — Migration Plan). This file is intentionally a plain PHP class so
// that `doctrine:schema:validate` and `doctrine:migrations:diff` do not
// attempt to materialise a table during the spec-PR's CI run. The field
// declarations below stay verbatim so PR-1 only needs to add the four
// outer attributes (#[ORM\Entity], #[ORM\Table], the two unique-keys and
// the indexes) plus the repositoryClass binding to go live.
//
// Target attributes for PR-1 to add:
//   #[ORM\Entity(repositoryClass: UserTenantAssignmentRepository::class)]
//   #[ORM\Table(name: 'user_tenant_assignments')]
//   #[ORM\UniqueConstraint(name: 'uniq_user_tenant_active', columns: ['user_id', 'tenant_id', 'revoked_at'])]
//   #[ORM\Index(name: 'idx_user_active', columns: ['user_id', 'revoked_at', 'valid_to'])]
//   #[ORM\Index(name: 'idx_tenant_active', columns: ['tenant_id', 'revoked_at', 'valid_to'])]
//   #[ORM\HasLifecycleCallbacks]
class UserTenantAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * At most one assignment per user may have `is_primary = true` AND
     * `revoked_at IS NULL`. Enforced at the DB level via a generated-column
     * unique index (see migration in PR-1).
     *
     * The primary assignment's tenant is what {@see User::getTenant()} (the
     * deprecated BC-shim) returns. Switching primaries is an admin-gated
     * operation that emits a `tenant_primary_change` audit-log entry.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isPrimary = false;

    /**
     * Optional per-tenant role override. NULL = the assignment inherits
     * {@see User::$roles} for this tenant. When set, REPLACES the user's
     * global role-set while operating in this tenant context (semantics:
     * override, not union or intersect — see spec §5.2).
     *
     * Voters MUST read role via
     * `UserTenantAssignmentService::getRolesForTenant($user, $tenant)`
     * NOT via `$user->getRoles()` directly when a per-tenant override exists.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $roleScope = null;

    /**
     * Assignment activation start. Defaults to NOW() on grant. Pre-dating
     * is allowed for "Erika starts on Tochter C on 2026-07-01" planning;
     * the assignment is invisible to `TenantContext::getActiveTenants()`
     * until `validFrom ≤ now()`.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $validFrom = null;

    /**
     * Optional assignment expiry. NULL = open-ended. Set for audit-pool
     * rotation ("Audit-Cycle Q3-2026 only") or contracted-DPO engagements
     * with a known end-date. Once `validTo < now()`, the assignment is
     * filtered out of `getActiveTenants()` without requiring a revoke.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    /**
     * Audit-trail attribution — who created this assignment. Nullable to
     * survive deletion of the granting admin's User row (`onDelete: SET NULL`).
     * Required for ISO 27001 §A.5.18 provisioning-evidence.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'granted_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $grantedAt = null;

    /**
     * Soft-delete marker. NULL = assignment is active (subject to
     * `validFrom`/`validTo` window). Non-NULL = explicitly revoked; the
     * row is preserved for audit-trail compliance.
     *
     * The unique-key `uniq_user_tenant_active` includes `revoked_at` so a
     * user CAN be re-granted access to the same tenant after revocation
     * (a new row is created, the old one stays for the audit log).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $revokeReason = null;

    /**
     * Optimistic-lock guard for concurrent admin edits (e.g. two admins
     * simultaneously toggling `isPrimary`). Lifecycle-spec convention since
     * Foundation P-4b — every multi-actor-mutated entity carries this.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->validFrom = new DateTimeImmutable();
        $this->grantedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;
        return $this;
    }

    /** @return list<string>|null */
    public function getRoleScope(): ?array
    {
        return $this->roleScope;
    }

    /** @param list<string>|null $roleScope */
    public function setRoleScope(?array $roleScope): static
    {
        $this->roleScope = $roleScope;
        return $this;
    }

    public function getValidFrom(): ?DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;
        return $this;
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?User $grantedBy): static
    {
        $this->grantedBy = $grantedBy;
        return $this;
    }

    public function getGrantedAt(): ?DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function setGrantedAt(DateTimeImmutable $grantedAt): static
    {
        $this->grantedAt = $grantedAt;
        return $this;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getRevokeReason(): ?string
    {
        return $this->revokeReason;
    }

    public function setRevokeReason(?string $revokeReason): static
    {
        $this->revokeReason = $revokeReason;
        return $this;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * True when this assignment is currently in effect — not revoked AND
     * within its valid-from/valid-to window relative to $at (default: now).
     *
     * Mirror this exact predicate in the repository's active-query filter.
     */
    public function isActive(?DateTimeInterface $at = null): bool
    {
        $at ??= new DateTimeImmutable();

        if ($this->revokedAt !== null) {
            return false;
        }
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }
        if ($this->validTo !== null && $at > $this->validTo) {
            return false;
        }
        return true;
    }
}
