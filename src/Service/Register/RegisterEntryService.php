<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\IsmsRegisterEntry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Write path of the register pages. Every change goes through the ORM, so the
 * audit log records it (AuditLogSubscriber lists IsmsRegisterEntry).
 */
final class RegisterEntryService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function save(IsmsRegisterEntry $entry): void
    {
        if ($entry->getId() === null) {
            $this->entityManager->persist($entry);
        } else {
            $entry->touch();
        }
        $this->entityManager->flush();
    }

    /** ISO 27001 9.3 / 10.1: the entry was checked and is still current. */
    public function markReviewed(IsmsRegisterEntry $entry): void
    {
        $entry->markReviewed(new DateTimeImmutable())->touch();
        $this->entityManager->flush();
    }

    /** Archives an active entry or reactivates an archived one; returns whether it is archived now. */
    public function toggleArchive(IsmsRegisterEntry $entry): bool
    {
        $entry->isArchived() ? $entry->restore() : $entry->archive(new DateTimeImmutable());
        $entry->touch();
        $this->entityManager->flush();

        return $entry->isArchived();
    }

    public function delete(IsmsRegisterEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }
}
