<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Attribute\PersonalDataResource;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * S33 (GDPR Art. 5 (1) f, 25, 32): who may open a record that describes a person as a
 * whole — a class marked `#[PersonalDataResource]` without a field list (data subject
 * request, data breach, consent, …). The same line the assistant connection draws
 * ({@see \App\Mcp\PersonalDataPolicy}), now for the web pages too.
 *
 * Granted to
 * - ROLE_MANAGER and above (the data protection officer's role inherits it);
 * - the person responsible for the record (`RECORD_RESPONSIBLE`: owner, assignee,
 *   deputy, holder of the duty's role — {@see RecordResponsibleVoter}).
 *
 * Not granted to the read-only reviewer roles (auditor, top management, group roles)
 * unless they are responsible for the record: reading other people's requests is not a
 * review task. Lists and the global search filter with the same attribute, record by
 * record, so list and detail page follow one rule.
 *
 * `#[IsGranted(PersonalDataRecordVoter::VIEW, subject: 'dsr')]`. Gate 104
 * (`tests/Security/PersonalDataPagesContractTest`) finds every page of such a class.
 */
final class PersonalDataRecordVoter extends Voter
{
    public const string VIEW = 'PERSONAL_DATA_VIEW';

    /** @var array<string, bool> */
    private array $wholeClass = [];

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && is_object($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive() || !is_object($subject)) {
            return false;
        }
        if (!$this->describesPerson($subject::class)) {
            // Not a record about a person as a whole: the page's own rules decide.
            return true;
        }
        if ($this->accessDecisionManager->decide($token, ['ROLE_MANAGER'])) {
            return true;
        }

        return $this->accessDecisionManager->decide($token, [RecordResponsibleVoter::RESPONSIBLE], $subject);
    }

    /** Is the class marked `#[PersonalDataResource]` as a whole (no field list)? */
    private function describesPerson(string $class): bool
    {
        if (is_subclass_of($class, \Doctrine\Persistence\Proxy::class)) {
            $class = (string) get_parent_class($class); // a lazy-loaded record's proxy class carries no attributes
        }
        if (!array_key_exists($class, $this->wholeClass)) {
            $attributes = class_exists($class)
                ? (new \ReflectionClass($class))->getAttributes(PersonalDataResource::class)
                : [];
            $this->wholeClass[$class] = $attributes !== [] && !$attributes[0]->newInstance()->isFieldLevel();
        }

        return $this->wholeClass[$class];
    }
}
