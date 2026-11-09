<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Suggestion;
use App\Enum\SuggestionOrigin;
use App\Service\Responsibility\Responsibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Who decides a suggestion: whoever is responsible for the record it is about
 * (risk owner, asset owner, … through that record's deadline source), else the
 * kind's decision roles, else the admins (ResponsibilityResolver order).
 * Import matches (legacy owner texts) are data repair and stay with the
 * kind's roles, and so do kinds whose write needs a role in the UI that a
 * record holder need not have (S30: the VVT entry, the register, the
 * management review) — the holder would be reminded of what they may not do.
 */
class SuggestionResponsibility
{
    /** @var array<string, SuggestionKind>|null */
    private ?array $kinds = null;

    /**
     * @param iterable<SuggestionKind> $kindIterator
     */
    public function __construct(
        #[AutowireIterator('app.suggestion_kind')] private readonly iterable $kindIterator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?SuggestionTargetAccess $targetAccess = null,
    ) {
    }

    public function of(Suggestion $suggestion): Responsibility
    {
        $kind = $this->kind($suggestion->getKind());
        $roles = $kind?->decisionRoles() ?? ['ROLE_MANAGER'];
        if ($suggestion->getOrigin() === SuggestionOrigin::Import
            || ($kind !== null && ($this->targetAccess?->roleFloor($kind) ?? []) !== [])) {
            return Responsibility::roles(...$roles);
        }

        return Responsibility::ofRecord($this->subject($suggestion))->orRoles(...$roles);
    }

    public function subject(Suggestion $suggestion): ?object
    {
        $class = $suggestion->getSubjectClass();
        $id = $suggestion->getSubjectId();
        if ($class === null || $id === null || !class_exists($class)) {
            return null;
        }

        try {
            return $this->entityManager->find($class, $id);
        } catch (\Throwable) {
            return null;
        }
    }

    private function kind(string $key): ?SuggestionKind
    {
        if ($this->kinds === null) {
            $this->kinds = [];
            foreach ($this->kindIterator as $kind) {
                $this->kinds[$kind->key()] = $kind;
            }
        }

        return $this->kinds[$key] ?? null;
    }
}
