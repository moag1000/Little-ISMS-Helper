<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ISMSObjective;
use App\Entity\User;
use App\Security\Persona\PersonaResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who maintains the ISMS steering records: the context of the organisation
 * (ISO 27001 4.1–4.3) and the information security objectives (6.2).
 *
 * That is the ISB's and the CISO's duty, not an administration task — so the
 * attribute is granted to those personas (admins reach both through the role
 * hierarchy). One attribute for every steering record, checked by controllers
 * (`#[IsGranted(IsmsSteeringVoter::EDIT)]`) and templates (`is_granted('ISMS_STEERING_EDIT')`).
 *
 * @extends Voter<string, mixed>
 */
final class IsmsSteeringVoter extends Voter
{
    public const string EDIT = 'ISMS_STEERING_EDIT';

    /**
     * Record a measurement of one objective (subject: ISMSObjective): the
     * steering roles plus the people named on the objective as measuring or
     * responsible — they get the measurement deadline, so they can close it.
     */
    public const string MEASURE = 'ISMS_OBJECTIVE_MEASURE';

    /** Roles that steer the ISMS (role hierarchy applies). */
    public const array STEERING_ROLES = ['ROLE_ISB', 'ROLE_CISO'];

    public function __construct(private readonly PersonaResolver $personaResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT || ($attribute === self::MEASURE && $subject instanceof ISMSObjective);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }
        if ($this->personaResolver->reaches($user, ...self::STEERING_ROLES)) {
            return true;
        }
        if ($attribute !== self::MEASURE || !$user instanceof User || !$subject instanceof ISMSObjective
            || $subject->getTenant()?->getId() !== $user->getTenant()?->getId()) {
            return false;
        }

        return in_array($user, [
            $subject->getMeasurementPerson()?->getLinkedUser(),
            $subject->getResponsiblePersonUser(),
            $subject->getResponsiblePersonRef()?->getLinkedUser(),
        ], true);
    }
}
