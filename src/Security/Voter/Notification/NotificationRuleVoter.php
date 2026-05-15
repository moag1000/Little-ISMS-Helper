<?php

declare(strict_types=1);

namespace App\Security\Voter\Notification;

use App\Entity\Notification\NotificationRule;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Controls access to NotificationRule entities.
 *
 * Attributes: VIEW | EDIT | DELETE
 * Gate:       notifications module active + tenant isolation
 * Requires:   ROLE_MANAGER for EDIT/DELETE
 */
class NotificationRuleVoter extends Voter
{
    public const string VIEW   = 'view';
    public const string EDIT   = 'edit';
    public const string DELETE = 'delete';

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof NotificationRule;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var NotificationRule $rule */
        $rule = $subject;

        // Module gate
        if (!$this->moduleConfiguration->isModuleActive('notifications')) {
            return false;
        }

        // Admins bypass tenant check
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Tenant isolation
        if ($rule->getTenant()?->getId() !== $user->getTenant()?->getId()) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::EDIT,
            self::DELETE => in_array('ROLE_MANAGER', $user->getRoles(), true),
            default      => false,
        };
    }
}
