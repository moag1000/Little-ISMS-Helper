<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\Responsibility\ResponsibilityResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * "Is this user the one who has to act on this record?" — answered by
 * {@see ResponsibilityResolver} through the record's deadline source (owner,
 * assignee, deputy, fallback role of that duty). Whoever My Day or the
 * reminder mail sends to a record may act on it there.
 *
 * Actions that a role may do anyway accept "role OR responsible" with
 * {@see MANAGER_OR_RESPONSIBLE}:
 *
 *     #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'dsr')]
 *
 * ReadOnlyRoleSubscriber lets such actions through for reviewer roles (a
 * member of top management named as task holder marks it done), because this
 * voter has checked the assignment. The record's organisation is part of the
 * answer: the resolver only returns accounts of the record's own tenant.
 */
final class RecordResponsibleVoter extends Voter
{
    public const string RESPONSIBLE = 'RECORD_RESPONSIBLE';

    /** Expression for #[IsGranted]: manager scope, or responsible for the subject. */
    public const string MANAGER_OR_RESPONSIBLE = 'is_granted("ROLE_MANAGER") or is_granted("RECORD_RESPONSIBLE", subject)';

    /** Expression for #[IsGranted] on read pages: reviewer scope, or responsible for the subject. */
    public const string AUDITOR_OR_RESPONSIBLE = 'is_granted("ROLE_AUDITOR") or is_granted("RECORD_RESPONSIBLE", subject)';

    /**
     * Expression for #[IsGranted] on records everyone of the organisation may
     * edit (Maßnahmen): the `edit` voter, but for the read-only reviewer roles
     * (auditor, top management without ROLE_MANAGER) only when they are the
     * responsible person — the same line ReadOnlyRoleSubscriber draws.
     */
    public const string EDIT_OR_RESPONSIBLE = 'is_granted("RECORD_RESPONSIBLE", subject)'
        . ' or (is_granted("edit", subject) and (is_granted("ROLE_MANAGER") or not is_granted("ROLE_AUDITOR")))';

    public function __construct(
        // Lazy: the resolver collects every deadline source; some of them use
        // the authorization checker, which collects this voter.
        #[Autowire(lazy: true)]
        private readonly ResponsibilityResolver $responsibilityResolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::RESPONSIBLE && is_object($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive() || !is_object($subject)) {
            return false;
        }

        return $this->responsibilityResolver->isResponsibleFor($user, $subject);
    }
}
