<?php

declare(strict_types=1);

namespace App\Security\Voter;

use ReflectionClass;
use App\Entity\ActionItem;
use App\Entity\Asset;
use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Control;
use App\Entity\Department;
use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\EvidenceReverificationTask;
use App\Entity\IdentityProvider;
use App\Entity\Incident;
use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Risk;
use App\Entity\RoadmapTask;
use App\Entity\Team;
use App\Entity\Training;
use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Entity Voter
 *
 * Generic fallback voter providing dynamic permission checking for any entity.
 * Implements convention-based authorization using entity name and action patterns.
 *
 * Supported Operations:
 * - VIEW: View entity details
 * - CREATE: Create new entities
 * - EDIT: Modify existing entities
 * - DELETE: Remove entities
 * - APPROVE: Approve entity workflows
 * - EXPORT: Export entity data
 *
 * Security Rules:
 * - Abstains on (class, attribute) pairs a dedicated voter decides
 *   ({@see DEDICATED}) — with the default "affirmative" strategy a grant
 *   from this permission-based fallback would otherwise override the
 *   dedicated voter's deny (e.g. its tenant isolation).
 * - Inactive users cannot perform any actions
 * - Subjects with getTenant() go through the shared tenant-scope guard:
 *   ROLE_SUPER_ADMIN everywhere, everyone else (ROLE_ADMIN included) only
 *   inside the own tenant; view/export also down the holding tree for
 *   ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR; a NULL tenant fails closed
 * - ROLE_ADMIN then bypasses the permission check
 * - Dynamic permission resolution: {entity_name}.{action}
 * - Works with any entity object through reflection
 *
 * Permission Resolution:
 * Example: Checking 'edit' on a Training entity resolves to 'training.edit' permission
 * This provides flexible authorization without creating entity-specific voters
 *
 * Best Practice:
 * - Use specific voters (AssetVoter, RiskVoter, etc.) for complex entity-specific logic
 * - Use this voter as a fallback for simple entities without special authorization rules
 */
final class EntityVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
    }

    // Entity actions
    public const string VIEW = 'view';
    public const string CREATE = 'create';
    public const string EDIT = 'edit';
    public const string DELETE = 'delete';
    public const string APPROVE = 'approve';
    public const string EXPORT = 'export';

    /**
     * Subject classes and attributes answered by a dedicated voter. This
     * voter abstains there. Kept in sync with the voters' supports() by
     * EntityVoterDedicatedMapTest.
     *
     * @var array<class-string, list<string>>
     */
    public const array DEDICATED = [
        ActionItem::class => [self::VIEW, self::EDIT, self::DELETE],
        Asset::class => [self::VIEW, self::EDIT, self::DELETE],
        DoraRegisterOfInformation::class => [self::VIEW, self::EXPORT],
        Nis2RegistrationProfile::class => [self::VIEW, self::EDIT, self::EXPORT],
        Control::class => [self::VIEW, self::EDIT, self::DELETE],
        Department::class => [self::VIEW, self::EDIT, self::DELETE],
        Document::class => [self::VIEW, self::EDIT, self::DELETE],
        DocumentVersion::class => [self::VIEW, self::DELETE],
        EvidenceReverificationTask::class => [self::VIEW, self::DELETE],
        IdentityProvider::class => [self::VIEW, self::EDIT, self::DELETE],
        Incident::class => [self::VIEW, self::EDIT, self::DELETE],
        NotificationChannel::class => [self::VIEW, self::EDIT, self::DELETE],
        NotificationRule::class => [self::VIEW, self::EDIT, self::DELETE],
        Risk::class => [self::VIEW, self::EDIT, self::DELETE],
        RoadmapTask::class => [self::VIEW, self::EDIT, self::DELETE],
        Team::class => [self::VIEW, self::EDIT, self::DELETE],
        Training::class => [self::VIEW, self::EDIT],
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        // This voter supports these actions
        $supportedActions = [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
            self::APPROVE,
            self::EXPORT,
        ];

        if (!in_array($attribute, $supportedActions)) {
            return false;
        }

        if (!is_object($subject)) {
            return false;
        }

        // Abstain where a dedicated voter decides.
        foreach (self::DEDICATED as $class => $attributes) {
            if ($subject instanceof $class && in_array($attribute, $attributes, true)) {
                return false;
            }
        }

        return true;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // User must be logged in
        if (!$user instanceof User) {
            return false;
        }

        // Inactive users cannot do anything
        if (!$user->isActive()) {
            return false;
        }

        // Tenant-owned subjects: shared scope guard (SUPER_ADMIN everywhere,
        // ROLE_ADMIN only inside its own tenant, fail closed on NULL).
        if (method_exists($subject, 'getTenant')) {
            $tenant = $subject->getTenant();
            $isRead = in_array($attribute, [self::VIEW, self::EXPORT], true);
            $scope = $this->decideTenantScope($user, $tenant instanceof Tenant ? $tenant : null, $isRead);
            if ($scope !== null) {
                return $scope;
            }
        } elseif ($this->hasReachableRole($user, 'ROLE_ADMIN')) {
            return true;
        }

        // Get entity name
        $entityClass = $subject::class;
        $entityName = strtolower(new ReflectionClass($entityClass)->getShortName());

        // Check permission based on entity and action
        $permissionName = $entityName . '.' . $attribute;

        return $user->hasPermission($permissionName);
    }
}
