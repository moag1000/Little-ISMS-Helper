<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DocumentSection;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * DocumentSectionVoter — Phase 4-C / Sprint W3-C "DPO Veto Mechanic".
 *
 * Enforces section-level authorization for the per-section sub-workflow
 * defined in `docs/plans/policy-wizard/06-dpo-input.md` §0.A.
 *
 * Privacy sections (sectionKey starting with `privacy_`) are exclusively
 * approve-/reject-able by ROLE_DPO. ROLE_CISO and ROLE_TOP_MGMT can VIEW
 * privacy sections but explicitly cannot approve / reject — this is the
 * GDPR Art. 38(3) DPO independence carve-out at the section level
 * (§0.A.4 of the spec). ROLE_SUPER_ADMIN is the universal bypass to stay
 * consistent with every other voter in this codebase.
 *
 * Supported attributes:
 *   DOCUMENT_SECTION_VIEW    — read access to the section
 *   DOCUMENT_SECTION_APPROVE — DPO sign-off / approved transition
 *   DOCUMENT_SECTION_REJECT  — DPO veto / rejected transition
 */
class DocumentSectionVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public const string VIEW    = 'DOCUMENT_SECTION_VIEW';
    public const string APPROVE = 'DOCUMENT_SECTION_APPROVE';
    public const string REJECT  = 'DOCUMENT_SECTION_REJECT';

    private const array SUPPORTED = [
        self::VIEW,
        self::APPROVE,
        self::REJECT,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED, true)
            && $subject instanceof DocumentSection;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if (!$user->isActive()) {
            return false;
        }
        if (!$subject instanceof DocumentSection) {
            return false;
        }

        // ROLE_SUPER_ADMIN bypasses every check — consistent with the
        // rest of the voter suite (PolicyWizardVoter, DocumentVoter, …).
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Tenant isolation FIRST: even view access requires same-tenant
        // (or a holding-tree read for ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR).
        if (!$this->subjectIsInUserScope($user, $subject)) {
            return false;
        }

        return match ($attribute) {
            self::VIEW    => $this->canView($user, $subject),
            self::APPROVE => $this->canApprove($user, $subject),
            self::REJECT  => $this->canReject($user, $subject),
            default       => false,
        };
    }

    /**
     * VIEW: privacy sections are still readable by CISO + Top-Mgmt
     * (Art. 38(3) carve-out applies to *write* access, not read). DPO
     * obviously sees them. Anyone in the same tenant with one of the
     * curator roles may view.
     */
    private function canView(User $user, DocumentSection $section): bool
    {
        return $this->hasAnyRole($user, [
            'ROLE_DPO',
            'ROLE_CISO',
            'ROLE_TOP_MGMT',
            'ROLE_AUDITOR',
            'ROLE_ADMIN',
            'ROLE_GROUP_CISO',
            'ROLE_KONZERN_AUDITOR',
        ]);
    }

    /**
     * APPROVE: only ROLE_DPO may approve a privacy section. Non-privacy
     * sections (introduced as future extension) keep the door open for
     * the section's owner role; for v1 we conservatively deny anyone
     * other than DPO.
     */
    private function canApprove(User $user, DocumentSection $section): bool
    {
        if ($section->isPrivacySection()) {
            // GDPR Art. 38(3) DPO independence — CISO + Top-Mgmt MAY NOT
            // approve a privacy section, even if they own the host doc.
            return $this->hasAnyRole($user, ['ROLE_DPO']);
        }

        // Non-privacy gated sections — left to ROLE_CISO until W3 ships
        // additional section-types (out of scope for this sprint).
        return $this->hasAnyRole($user, ['ROLE_CISO']);
    }

    /**
     * REJECT mirrors APPROVE: DPO is the only role that may veto a
     * privacy section (Art. 38(3)).
     */
    private function canReject(User $user, DocumentSection $section): bool
    {
        if ($section->isPrivacySection()) {
            return $this->hasAnyRole($user, ['ROLE_DPO']);
        }
        return $this->hasAnyRole($user, ['ROLE_CISO']);
    }

    /**
     * @param list<string> $roles
     */
    private function hasAnyRole(User $user, array $roles): bool
    {
        $userRoles = $user->getRoles();
        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }
        return false;
    }

    private function subjectIsInUserScope(User $user, DocumentSection $section): bool
    {
        $sectionTenant = $section->getTenant();
        $userTenant = $user->getTenant();
        if ($sectionTenant === null || $userTenant === null) {
            return false;
        }
        if ($sectionTenant === $userTenant) {
            return true;
        }
        if ($sectionTenant->getId() !== null
            && $sectionTenant->getId() === $userTenant->getId()
        ) {
            return true;
        }
        return $this->canReadAcrossHoldingTree($user, $sectionTenant);
    }
}
