<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Repository\IsmsRegisterEntryRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Periodic review of the norm registers (ISO 27001 4.1–7.4): every active entry
 * with a next review date. Owner and deputies act; without an owner the
 * register's maintaining persona (legal → compliance, opportunity → risk, else ISB).
 */
final class RegisterReviewSource implements DeadlineSource
{
    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
    ) {
    }

    public function slug(): string
    {
        return 'register_review';
    }

    public function label(): string
    {
        return 'Register-Prüfung';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<IsmsRegisterEntry> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->entries->findOpenWithReview($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof IsmsRegisterEntry);

        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof IsmsRegisterEntry);

        return (string) $item->getTitle();
    }

    /** Reviews recur: "geprüft" moves the date forward, archiving ends the duty. */
    public function isCompleted(object $item): bool
    {
        assert($item instanceof IsmsRegisterEntry);

        return $item->isArchived();
    }

    public function refId(object $item): int
    {
        assert($item instanceof IsmsRegisterEntry);

        return (int) $item->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof IsmsRegisterEntry;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof IsmsRegisterEntry);

        return Responsibility::holders($item->getOwnerUser(), $item->getOwnerPerson())
            ->withDeputies(...$item->getDeputyPersons()->toArray())
            ->orRoles($item->getType()->maintainerRole());
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof IsmsRegisterEntry);

        return new RecordLink('app_register_entry_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'register_review', leadDays: 30, normReference: 'ISO 27001 4.1–7.4');
    }
}
