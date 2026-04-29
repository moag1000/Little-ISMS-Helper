<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Generic tenant-isolation voter for API Platform entities.
 *
 * Covers all entities that have a getTenant() method but lack a dedicated
 * voter with tenant checks (Supplier, Training, ChangeRequest, Location,
 * Person, BusinessContinuityPlan, InterestedParty, ThreatIntelligence,
 * ISMSObjective, BCExercise, InternalAudit, ISMSContext, CryptographicOperation,
 * PhysicalAccessLog).
 *
 * Entities with dedicated voters (Asset, Risk, Incident, Control, Document)
 * are NOT handled here — their voters take precedence via supports().
 *
 * PenTest Finding PT-005 (CVSS 7.1): API endpoints allowed cross-tenant access.
 *
 * Attributes:
 *   API_VIEW   — read single entity
 *   API_EDIT   — update entity
 *   API_DELETE — remove entity
 *   API_CREATE — create entity (tenant set by TenantAwareStateProcessor)
 */
class ApiTenantVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public const string API_VIEW = 'API_VIEW';
    public const string API_EDIT = 'API_EDIT';
    public const string API_DELETE = 'API_DELETE';
    public const string API_CREATE = 'API_CREATE';

    /**
     * Entity classes handled by dedicated voters — skip them here.
     */
    private const array EXCLUDED_CLASSES = [
        \App\Entity\Asset::class,
        \App\Entity\Risk::class,
        \App\Entity\Incident::class,
        \App\Entity\Control::class,
        \App\Entity\Document::class,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::API_VIEW, self::API_EDIT, self::API_DELETE, self::API_CREATE])) {
            return false;
        }

        if (!is_object($subject)) {
            return false;
        }

        // Skip entities with dedicated voters
        foreach (self::EXCLUDED_CLASSES as $excluded) {
            if ($subject instanceof $excluded) {
                return false;
            }
        }

        // Only handle entities with getTenant()
        return method_exists($subject, 'getTenant');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // ROLE_ADMIN bypasses tenant checks
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $userTenant = $user->getTenant();
        if (!$userTenant instanceof Tenant) {
            return false;
        }

        $entityTenant = $subject->getTenant();

        return match ($attribute) {
            self::API_VIEW => $this->canView($entityTenant, $user, $userTenant),
            self::API_EDIT => $this->canEdit($entityTenant, $userTenant),
            self::API_CREATE => true, // Tenant set by processor; user is authenticated
            self::API_DELETE => false, // Only ROLE_ADMIN (handled above)
            default => false,
        };
    }

    private function canView(?Tenant $entityTenant, User $user, Tenant $userTenant): bool
    {
        if ($entityTenant === $userTenant) {
            return true;
        }
        if ($entityTenant !== null && $entityTenant->getId() === $userTenant->getId()) {
            return true;
        }
        return $this->canReadAcrossHoldingTree($user, $entityTenant);
    }

    private function canEdit(?Tenant $entityTenant, Tenant $userTenant): bool
    {
        if ($entityTenant === $userTenant) {
            return true;
        }
        return $entityTenant !== null && $entityTenant->getId() === $userTenant->getId();
    }
}
