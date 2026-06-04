<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnswerLibraryEntry;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AnswerLibraryEntryRepository;
use App\Service\Fte\FteRecorderService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F44 — Answer Library Service.
 *
 * Manages security-questionnaire answer entries and records reuse events
 * through FteRecorderService so every reuse contributes to the FTE/ROI counter (F11).
 *
 * Audit logging uses AuditLogger::logCustom() — one entry per action,
 * no duplicate audit rows for analytics side-effects.
 */
final class AnswerLibraryService
{
    public function __construct(
        private readonly EntityManagerInterface      $entityManager,
        private readonly AnswerLibraryEntryRepository $repository,
        private readonly FteRecorderService          $fteRecorder,
        private readonly AuditLogger                 $auditLogger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reuse recording
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record that $user has reused $entry.
     *
     * Side-effects (all idempotent on failure):
     *   1. Increments entry.useCount
     *   2. Sets entry.lastUsedAt = now
     *   3. Calls FteRecorderService::recordAnswerReuse() — feeds the ROI counter
     *   4. Emits single AuditLogger::logCustom() entry
     */
    public function recordReuse(AnswerLibraryEntry $entry, User $user): void
    {
        $entry->incrementUseCount();
        $entry->setLastUsedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        // F11 FTE tracking — reuse feeds the ROI counter (must NOT throw)
        $this->fteRecorder->recordAnswerReuse($entry, $user);

        // Single audit entry (ISO 27001 Cl. 7.5.3)
        $this->auditLogger->logCustom(
            action:      'answer_library.reuse',
            entityType:  'AnswerLibraryEntry',
            entityId:    $entry->getId(),
            oldValues:   null,
            newValues:   [
                'use_count'   => $entry->getUseCount(),
                'last_used_at' => $entry->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            ],
            description: sprintf(
                'Answer library entry #%d reused by user #%d (category: %s)',
                (int) $entry->getId(),
                (int) $user->getId(),
                $entry->getCategory(),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create and persist a new answer library entry.
     *
     * @param list<string> $tags
     */
    public function createEntry(
        Tenant  $tenant,
        User    $createdBy,
        string  $question,
        string  $answer,
        string  $category = AnswerLibraryEntry::CATEGORY_GENERAL,
        array   $tags = [],
    ): AnswerLibraryEntry {
        $entry = new AnswerLibraryEntry();
        $entry->setTenant($tenant);
        $entry->setCreatedBy($createdBy);
        $entry->setQuestion($question);
        $entry->setAnswer($answer);
        $entry->setCategory($category);
        $entry->setTags($tags);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action:      'answer_library.create',
            entityType:  'AnswerLibraryEntry',
            entityId:    $entry->getId(),
            oldValues:   null,
            newValues:   [
                'question' => mb_substr($question, 0, 120),
                'category' => $category,
                'tags'     => $tags,
            ],
            description: sprintf('Answer library entry created (category: %s)', $category),
        );

        return $entry;
    }

    /**
     * Update an existing entry's mutable fields and flush.
     *
     * @param list<string> $tags
     */
    public function updateEntry(
        AnswerLibraryEntry $entry,
        string             $question,
        string             $answer,
        string             $category,
        array              $tags,
    ): void {
        $old = [
            'question' => mb_substr($entry->getQuestion(), 0, 120),
            'category' => $entry->getCategory(),
            'tags'     => $entry->getTags(),
        ];

        $entry->setQuestion($question);
        $entry->setAnswer($answer);
        $entry->setCategory($category);
        $entry->setTags($tags);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action:      'answer_library.update',
            entityType:  'AnswerLibraryEntry',
            entityId:    $entry->getId(),
            oldValues:   $old,
            newValues:   [
                'question' => mb_substr($question, 0, 120),
                'category' => $category,
                'tags'     => $tags,
            ],
            description: sprintf('Answer library entry #%d updated', (int) $entry->getId()),
        );
    }

    /**
     * Delete an entry after asserting tenant ownership.
     * The caller MUST have validated tenant-isolation before calling this method.
     */
    public function deleteEntry(AnswerLibraryEntry $entry): void
    {
        $snapshot = [
            'id'       => $entry->getId(),
            'question' => mb_substr($entry->getQuestion(), 0, 120),
            'category' => $entry->getCategory(),
        ];

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action:      'answer_library.delete',
            entityType:  'AnswerLibraryEntry',
            entityId:    $snapshot['id'],
            oldValues:   $snapshot,
            newValues:   null,
            description: sprintf('Answer library entry #%d deleted', (int) $snapshot['id']),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Search
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Search entries by keyword and/or category, scoped to the tenant.
     *
     * @return AnswerLibraryEntry[]
     */
    public function search(
        Tenant  $tenant,
        string  $keyword = '',
        ?string $category = null,
    ): array {
        if ($keyword !== '') {
            return $this->repository->searchByKeyword($tenant, $keyword, $category);
        }

        if ($category !== null && $category !== '') {
            return $this->repository->findByTenantAndCategory($tenant, $category);
        }

        return $this->repository->findByTenant($tenant);
    }
}
