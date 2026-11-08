<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Security\SetupAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may run a setup wizard action: everybody until the setup is complete, afterwards
 * only the super admin. Class-level #[IsGranted(SetupAccessChecker::ACCESS)] on every
 * wizard controller, so a new action is covered without remembering a guard call.
 */
final class SetupAccessVoter extends Voter
{
    public function __construct(
        private readonly SetupAccessChecker $setupAccessChecker,
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === SetupAccessChecker::ACCESS;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$this->setupAccessChecker->isSetupComplete()) {
            return true;
        }

        return $token->getUser() !== null && $this->security->isGranted('ROLE_SUPER_ADMIN');
    }
}
