<?php

declare(strict_types=1);

namespace App\Security\Voter;

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

    /** Roles that steer the ISMS (role hierarchy applies). */
    public const array STEERING_ROLES = ['ROLE_ISB', 'ROLE_CISO'];

    public function __construct(private readonly PersonaResolver $personaResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof UserInterface && $this->personaResolver->reaches($user, ...self::STEERING_ROLES);
    }
}
