<?php

declare(strict_types=1);

namespace App\Security\Voter\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\User;
use App\Security\Voter\HoldingTreeAccessTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Controls access to NotificationChannel entities.
 *
 * Attributes: VIEW | EDIT | DELETE
 * Gate:       notifications module active + tenant isolation
 * Requires:   ROLE_MANAGER for EDIT/DELETE
 */
final class NotificationChannelVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public const string VIEW   = 'view';
    public const string EDIT   = 'edit';
    public const string DELETE = 'delete';

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {}

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof NotificationChannel;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var NotificationChannel $channel */
        $channel = $subject;

        // Module gate
        if (!$this->moduleConfiguration->isModuleActive('notifications')) {
            return false;
        }

        // Tenant scope (fail closed): SUPER_ADMIN everywhere, ROLE_ADMIN
        // only inside its own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $channel->getTenant(), $attribute === self::VIEW);
        if ($scope !== null) {
            return $scope;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::EDIT,
            self::DELETE => $this->hasReachableRole($user, 'ROLE_MANAGER'),
            default      => false,
        };
    }
}
