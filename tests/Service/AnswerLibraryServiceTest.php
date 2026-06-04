<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnswerLibraryEntry;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AnswerLibraryEntryRepository;
use App\Service\AnswerLibraryService;
use App\Service\AuditLogger;
use App\Service\Fte\FteRecorderService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * F44 — Unit tests for AnswerLibraryService::recordReuse.
 *
 * Verifies that:
 *   1. useCount is incremented and lastUsedAt is set on the entry.
 *   2. FteRecorderService::recordAnswerReuse() is called exactly once.
 *   3. AuditLogger::logCustom() is called exactly once with the correct action.
 */
#[AllowMockObjectsWithoutExpectations]
class AnswerLibraryServiceTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&AnswerLibraryEntryRepository $repository;
    private MockObject&FteRecorderService $fteRecorder;
    private MockObject&AuditLogger $auditLogger;
    private AnswerLibraryService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository    = $this->createMock(AnswerLibraryEntryRepository::class);
        $this->fteRecorder   = $this->createMock(FteRecorderService::class);
        $this->auditLogger   = $this->createMock(AuditLogger::class);

        $this->service = new AnswerLibraryService(
            $this->entityManager,
            $this->repository,
            $this->fteRecorder,
            $this->auditLogger,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recordReuse
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testRecordReuseIncrementsUseCount(): void
    {
        $tenant = $this->makeTenant(42);
        $user   = $this->makeUser(7);
        $entry  = $this->makeEntry($tenant, initialUseCount: 3);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->recordReuse($entry, $user);

        self::assertSame(4, $entry->getUseCount(), 'useCount should be incremented by 1');
    }

    #[Test]
    public function testRecordReuseSetsLastUsedAt(): void
    {
        $tenant = $this->makeTenant(42);
        $user   = $this->makeUser(7);
        $entry  = $this->makeEntry($tenant, initialUseCount: 0);

        $before = new \DateTimeImmutable('-1 second');

        $this->entityManager->method('flush');
        $this->fteRecorder->method('recordAnswerReuse');
        $this->auditLogger->method('logCustom');

        $this->service->recordReuse($entry, $user);

        $lastUsed = $entry->getLastUsedAt();
        self::assertNotNull($lastUsed, 'lastUsedAt should be set after reuse');
        self::assertGreaterThanOrEqual(
            $before,
            \DateTimeImmutable::createFromInterface($lastUsed),
            'lastUsedAt should be >= the timestamp before recordReuse was called',
        );
    }

    #[Test]
    public function testRecordReuseCallsFteRecorderExactlyOnce(): void
    {
        $tenant = $this->makeTenant(1);
        $user   = $this->makeUser(2);
        $entry  = $this->makeEntry($tenant, initialUseCount: 0);

        $this->entityManager->method('flush');
        $this->auditLogger->method('logCustom');

        // F11 — FteRecorderService MUST be called exactly once per reuse
        $this->fteRecorder
            ->expects(self::once())
            ->method('recordAnswerReuse')
            ->with(
                self::identicalTo($entry),
                self::identicalTo($user),
            );

        $this->service->recordReuse($entry, $user);
    }

    #[Test]
    public function testRecordReuseCallsAuditLoggerExactlyOnce(): void
    {
        $tenant = $this->makeTenant(1);
        $user   = $this->makeUser(2);
        $entry  = $this->makeEntry($tenant, initialUseCount: 5);

        $this->entityManager->method('flush');
        $this->fteRecorder->method('recordAnswerReuse');

        // ISO 27001 Cl. 7.5.3 — single audit entry per reuse action
        $this->auditLogger
            ->expects(self::once())
            ->method('logCustom')
            ->with(
                action:     'answer_library.reuse',
                entityType: 'AnswerLibraryEntry',
                entityId:   self::anything(),
                oldValues:  null,
                newValues:  self::isArray(),
                description: self::stringContains('reused by user'),
            );

        $this->service->recordReuse($entry, $user);
    }

    #[Test]
    public function testRecordReuseWithZeroInitialCount(): void
    {
        $tenant = $this->makeTenant(1);
        $user   = $this->makeUser(1);
        $entry  = $this->makeEntry($tenant, initialUseCount: 0);

        $this->entityManager->method('flush');
        $this->fteRecorder->method('recordAnswerReuse');
        $this->auditLogger->method('logCustom');

        $this->service->recordReuse($entry, $user);

        self::assertSame(1, $entry->getUseCount(), 'First reuse should set useCount to 1');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // createEntry
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testCreateEntryPersistsAndAudits(): void
    {
        $tenant = $this->makeTenant(1);
        $user   = $this->makeUser(1);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');
        $this->auditLogger->expects(self::once())->method('logCustom')
            ->with(action: 'answer_library.create', entityType: 'AnswerLibraryEntry');

        $entry = $this->service->createEntry(
            tenant:    $tenant,
            createdBy: $user,
            question:  'How do you handle data at rest?',
            answer:    'We use AES-256.',
            category:  AnswerLibraryEntry::CATEGORY_ENCRYPTION,
            tags:      ['encryption', 'iso27001'],
        );

        self::assertSame('How do you handle data at rest?', $entry->getQuestion());
        self::assertSame(AnswerLibraryEntry::CATEGORY_ENCRYPTION, $entry->getCategory());
        self::assertSame(['encryption', 'iso27001'], $entry->getTags());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeTenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function makeUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    private function makeEntry(Tenant $tenant, int $initialUseCount = 0): AnswerLibraryEntry
    {
        $entry = new AnswerLibraryEntry();
        $entry->setTenant($tenant);
        $entry->setQuestion('Test question?');
        $entry->setAnswer('Test answer.');
        $entry->setCategory(AnswerLibraryEntry::CATEGORY_GENERAL);
        $entry->setUseCount($initialUseCount);
        return $entry;
    }
}
