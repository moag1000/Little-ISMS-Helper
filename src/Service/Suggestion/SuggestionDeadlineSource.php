<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Repository\SuggestionRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Open suggestions as one deadline source (Paket 2 + 5): they show up in
 * My Day and the digest mail of whoever is responsible for the record the
 * suggestion is about. A suggestion is a decision; it is due two weeks after
 * it was made, so nothing sits in the queue unseen.
 */
final class SuggestionDeadlineSource implements DeadlineSource
{
    private const int DECIDE_WITHIN_DAYS = 14;

    public function __construct(
        private readonly SuggestionRepository $repository,
        private readonly SuggestionResponsibility $responsibility,
    ) {
    }

    public function slug(): string
    {
        return 'suggestions';
    }

    public function label(): string
    {
        return 'Vorschläge';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<Suggestion> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->repository->findPending($tenant);
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof Suggestion);

        return $item->getCreatedAt()->modify(sprintf('+%d days', self::DECIDE_WITHIN_DAYS));
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Suggestion);

        return $item->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Suggestion);

        return !$item->isPending();
    }

    public function refId(object $item): int
    {
        assert($item instanceof Suggestion);

        return (int) $item->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof Suggestion;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Suggestion);

        return $this->responsibility->of($item);
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Suggestion);
        $subject = $item->getSubjectClass() !== null && $item->getSubjectId() !== null
            ? ['subject' => $item->getSubjectClass() . '#' . $item->getSubjectId()]
            : ['kind' => $item->getKind()];

        return new RecordLink('app_suggestion_index', $subject);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'suggestions', leadDays: null, decision: true);
    }
}
