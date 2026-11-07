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
 * kind's roles.
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
    ) {
    }

    public function of(Suggestion $suggestion): Responsibility
    {
        $roles = $this->kind($suggestion->getKind())?->decisionRoles() ?? ['ROLE_MANAGER'];
        if ($suggestion->getOrigin() === SuggestionOrigin::Import) {
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
