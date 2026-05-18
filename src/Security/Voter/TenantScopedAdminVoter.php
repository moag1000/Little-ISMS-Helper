<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Tenant-Scoped Admin Voter.
 *
 * Foundation of the Role-Scope Architecture (Phase 1, spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`).
 *
 * Provides 8 attributes covering admin-scope and persona-visibility checks:
 *
 *  Admin-scope (write-capable):
 *  - ADMIN_OWN_TENANT    Admin acting inside their own tenant tree
 *  - ADMIN_ANY_TENANT    Admin acting on an arbitrary tenant
 *                        (SUPER_ADMIN only)
 *  - ADMIN_GLOBAL_OP     Cross-tenant / system operations
 *                        (SUPER_ADMIN only)
 *  - ADMIN_HOLDING_READ  Holding-level read access (GROUP_CISO /
 *                        KONZERN_AUDITOR / SUPER_ADMIN)
 *
 *  Persona (module-visibility):
 *  - PERSONA_CISO        Holder of ROLE_CISO
 *  - PERSONA_RISK        Holder of ROLE_RISK_MANAGER
 *  - PERSONA_DPO         Holder of ROLE_DPO
 *  - PERSONA_COMPLIANCE  Holder of ROLE_COMPLIANCE_MANAGER
 *
 * Subjects that resolve to a tenant:
 *  - Tenant instance     used as-is
 *  - int / numeric str   looked up via TenantRepository
 *  - null / '' / 'global' treated as "no specific tenant"
 *                        (route-level / fall-through to context)
 *
 * Persona attributes ignore the subject entirely — they are pure
 * role-presence checks.
 *
 * This voter intentionally does NOT modify TenantContext / Security
 * state. It is read-only from a session standpoint.
 */
final class TenantScopedAdminVoter extends Voter
{
    // Admin-scope attributes
    public const string ADMIN_OWN_TENANT   = 'ADMIN_OWN_TENANT';
    public const string ADMIN_ANY_TENANT   = 'ADMIN_ANY_TENANT';
    public const string ADMIN_GLOBAL_OP    = 'ADMIN_GLOBAL_OP';
    public const string ADMIN_HOLDING_READ = 'ADMIN_HOLDING_READ';

    // Persona attributes (module-visibility)
    public const string PERSONA_CISO       = 'PERSONA_CISO';
    public const string PERSONA_RISK       = 'PERSONA_RISK';
    public const string PERSONA_DPO        = 'PERSONA_DPO';
    public const string PERSONA_COMPLIANCE = 'PERSONA_COMPLIANCE';

    private const array SUPPORTED_ATTRIBUTES = [
        self::ADMIN_OWN_TENANT,
        self::ADMIN_ANY_TENANT,
        self::ADMIN_GLOBAL_OP,
        self::ADMIN_HOLDING_READ,
        self::PERSONA_CISO,
        self::PERSONA_RISK,
        self::PERSONA_DPO,
        self::PERSONA_COMPLIANCE,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::ADMIN_ANY_TENANT,
            self::ADMIN_GLOBAL_OP    => $this->security->isGranted('ROLE_SUPER_ADMIN'),
            self::ADMIN_OWN_TENANT   => $this->canAdministerInScope($subject),
            self::ADMIN_HOLDING_READ => $this->canReadHoldingTree($subject),
            self::PERSONA_CISO       => $this->security->isGranted('ROLE_CISO'),
            self::PERSONA_RISK       => $this->security->isGranted('ROLE_RISK_MANAGER'),
            self::PERSONA_DPO        => $this->security->isGranted('ROLE_DPO'),
            self::PERSONA_COMPLIANCE => $this->security->isGranted('ROLE_COMPLIANCE_MANAGER'),
            default                  => false,
        };
    }

    /**
     * ADMIN_OWN_TENANT: ROLE_SUPER_ADMIN always; ROLE_ADMIN only inside
     * the accessible tenant tree (own + descendants).
     */
    private function canAdministerInScope(mixed $subject): bool
    {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        // Route-level / no-subject call: require an active tenant context.
        if ($subject === null || $subject === '' || $subject === 'global') {
            return $this->tenantContext->hasTenant();
        }

        $tenant = $this->resolveTenant($subject);
        if (!$tenant instanceof Tenant) {
            return false;
        }

        return $this->tenantContext->canAccessTenant($tenant);
    }

    /**
     * ADMIN_HOLDING_READ: ROLE_SUPER_ADMIN always; ROLE_GROUP_CISO /
     * ROLE_KONZERN_AUDITOR within the accessible tenant tree.
     */
    private function canReadHoldingTree(mixed $subject): bool
    {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $hasHoldingRead = $this->security->isGranted('ROLE_GROUP_CISO')
            || $this->security->isGranted('ROLE_KONZERN_AUDITOR');
        if (!$hasHoldingRead) {
            return false;
        }

        if ($subject === null || $subject === '' || $subject === 'global') {
            return $this->tenantContext->hasTenant();
        }

        $tenant = $this->resolveTenant($subject);
        if (!$tenant instanceof Tenant) {
            return false;
        }

        return $this->tenantContext->canAccessTenant($tenant);
    }

    /**
     * Resolve a subject into a concrete Tenant, when possible.
     *
     * Accepted subject shapes:
     *  - Tenant instance     → returned as-is
     *  - int / numeric str   → repository lookup (null if not found)
     *  - null / '' / 'global' → null (caller must check)
     *  - anything else       → null
     */
    private function resolveTenant(mixed $subject): ?Tenant
    {
        if ($subject instanceof Tenant) {
            return $subject;
        }
        if (is_int($subject) && $subject > 0) {
            return $this->tenantRepository->find($subject);
        }
        if (is_string($subject) && $subject !== '' && $subject !== 'global' && ctype_digit($subject)) {
            $id = (int) $subject;
            return $id > 0 ? $this->tenantRepository->find($id) : null;
        }
        return null;
    }
}
