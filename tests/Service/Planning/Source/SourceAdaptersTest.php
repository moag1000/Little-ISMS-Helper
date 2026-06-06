<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning\Source;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\InternalAudit;
use App\Entity\ManagementReview;
use App\Enum\InternalAuditStatus;
use App\Enum\ManagementReviewStatus;
use App\Repository\AuditFindingRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ManagementReviewRepository;
use App\Service\Planning\Source\Adapter\AuditFindingAdapter;
use App\Service\Planning\Source\Adapter\CorrectiveActionAdapter;
use App\Service\Planning\Source\Adapter\InternalAuditAdapter;
use App\Service\Planning\Source\Adapter\ManagementReviewAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit smoke-tests for the four SourceAdapter implementations.
 *
 * Each adapter is instantiated with a createStub() repository (no expectations
 * — avoids the PHPUnit notice that failOnNotice=true would promote to a test
 * failure). Entity instances are constructed directly to validate accessor
 * routing and terminal-status detection.
 */
final class SourceAdaptersTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // CorrectiveActionAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testCorrectiveActionAdapterMeta(): void
    {
        $adapter = new CorrectiveActionAdapter(
            $this->createStub(CorrectiveActionRepository::class),
        );

        self::assertSame('corrective_action', $adapter->slug());
        self::assertSame('Korrekturmaßnahme', $adapter->label());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testCorrectiveActionAdapterDueDateAndTitle(): void
    {
        $adapter = new CorrectiveActionAdapter(
            $this->createStub(CorrectiveActionRepository::class),
        );

        $date = new DateTimeImmutable('2026-09-01');
        $item = (new CorrectiveAction())
            ->setTitle('Fix logging gap')
            ->setPlannedCompletionDate($date)
            ->setStatus(CorrectiveAction::STATUS_PLANNED);

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('Fix logging gap', $adapter->titleOf($item));
    }

    public function testCorrectiveActionAdapterTitleFallback(): void
    {
        $adapter = new CorrectiveActionAdapter(
            $this->createStub(CorrectiveActionRepository::class),
        );

        $item = new CorrectiveAction(); // title null, id null → '#' . (int)null = '#0'
        self::assertStringStartsWith('#', $adapter->titleOf($item));
    }

    public function testCorrectiveActionAdapterIsCompletedFalseForOpenStatus(): void
    {
        $adapter = new CorrectiveActionAdapter(
            $this->createStub(CorrectiveActionRepository::class),
        );

        foreach ([
            CorrectiveAction::STATUS_PLANNED,
            CorrectiveAction::STATUS_IN_PROGRESS,
            CorrectiveAction::STATUS_COMPLETED,  // completed ≠ terminal; awaits verification
        ] as $openStatus) {
            $item = (new CorrectiveAction())->setStatus($openStatus);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$openStatus}",
            );
        }
    }

    public function testCorrectiveActionAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new CorrectiveActionAdapter(
            $this->createStub(CorrectiveActionRepository::class),
        );

        foreach ([
            CorrectiveAction::STATUS_VERIFIED,
            CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
        ] as $terminalStatus) {
            $item = (new CorrectiveAction())->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AuditFindingAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testAuditFindingAdapterMeta(): void
    {
        $adapter = new AuditFindingAdapter(
            $this->createStub(AuditFindingRepository::class),
        );

        self::assertSame('audit_finding', $adapter->slug());
        self::assertSame('Audit-Feststellung', $adapter->label());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testAuditFindingAdapterDueDateAndTitle(): void
    {
        $adapter = new AuditFindingAdapter(
            $this->createStub(AuditFindingRepository::class),
        );

        $date = new DateTimeImmutable('2026-10-15');
        $item = (new AuditFinding())
            ->setTitle('Missing access-log retention')
            ->setDueDate($date)
            ->setStatus(AuditFinding::STATUS_OPEN);

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('Missing access-log retention', $adapter->titleOf($item));
    }

    public function testAuditFindingAdapterIsCompletedFalseForOpenStatus(): void
    {
        $adapter = new AuditFindingAdapter(
            $this->createStub(AuditFindingRepository::class),
        );

        foreach ([AuditFinding::STATUS_OPEN, AuditFinding::STATUS_IN_PROGRESS] as $status) {
            $item = (new AuditFinding())->setStatus($status);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$status}",
            );
        }
    }

    public function testAuditFindingAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new AuditFindingAdapter(
            $this->createStub(AuditFindingRepository::class),
        );

        foreach ([
            AuditFinding::STATUS_RESOLVED,
            AuditFinding::STATUS_VERIFIED,
            AuditFinding::STATUS_CLOSED,
        ] as $terminalStatus) {
            $item = (new AuditFinding())->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ManagementReviewAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testManagementReviewAdapterMeta(): void
    {
        $adapter = new ManagementReviewAdapter(
            $this->createStub(ManagementReviewRepository::class),
        );

        self::assertSame('management_review', $adapter->slug());
        self::assertSame('Management-Review', $adapter->label());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testManagementReviewAdapterDueDateAndTitle(): void
    {
        $adapter = new ManagementReviewAdapter(
            $this->createStub(ManagementReviewRepository::class),
        );

        $date = new DateTimeImmutable('2026-12-01');
        $item = (new ManagementReview())
            ->setTitle('Q4 Management Review')
            ->setReviewDate($date)
            ->setStatus(ManagementReviewStatus::Planned->value);

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('Q4 Management Review', $adapter->titleOf($item));
    }

    public function testManagementReviewAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new ManagementReviewAdapter(
            $this->createStub(ManagementReviewRepository::class),
        );

        foreach ([
            ManagementReviewStatus::Planned->value,
            ManagementReviewStatus::FollowUpRequired->value,
        ] as $status) {
            $item = (new ManagementReview())->setStatus($status);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$status}",
            );
        }
    }

    public function testManagementReviewAdapterIsCompletedTrueForTerminalStatus(): void
    {
        $adapter = new ManagementReviewAdapter(
            $this->createStub(ManagementReviewRepository::class),
        );

        $item = (new ManagementReview())->setStatus(ManagementReviewStatus::Completed->value);
        self::assertTrue($adapter->isCompleted($item));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // InternalAuditAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testInternalAuditAdapterMeta(): void
    {
        $adapter = new InternalAuditAdapter(
            $this->createStub(InternalAuditRepository::class),
        );

        self::assertSame('internal_audit', $adapter->slug());
        self::assertSame('Internes Audit', $adapter->label());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testInternalAuditAdapterDueDateAndTitle(): void
    {
        $adapter = new InternalAuditAdapter(
            $this->createStub(InternalAuditRepository::class),
        );

        $date = new DateTimeImmutable('2026-11-01');
        $item = (new InternalAudit())
            ->setTitle('ISMS Full Audit 2026')
            ->setPlannedDate($date)
            ->setAuditNumber('AUD-2026-01')
            ->setStatus(InternalAuditStatus::Planned->value);

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('ISMS Full Audit 2026', $adapter->titleOf($item));
    }

    public function testInternalAuditAdapterIsCompletedFalseForNonTerminalStatuses(): void
    {
        $adapter = new InternalAuditAdapter(
            $this->createStub(InternalAuditRepository::class),
        );

        // Non-terminal: still have transitions in LIFECYCLE_STAGES
        foreach ([
            InternalAuditStatus::Planned->value,
            InternalAuditStatus::Conducted->value,
            InternalAuditStatus::Reported->value,
            InternalAuditStatus::Approved->value,
            InternalAuditStatus::Rejected->value,
            InternalAuditStatus::InProgress->value,
            InternalAuditStatus::Completed->value,
            InternalAuditStatus::Postponed->value,
        ] as $status) {
            $item = (new InternalAudit())
                ->setAuditNumber('AUD-X')
                ->setStatus($status);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$status}",
            );
        }
    }

    public function testInternalAuditAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new InternalAuditAdapter(
            $this->createStub(InternalAuditRepository::class),
        );

        foreach ([
            InternalAuditStatus::Closed->value,
            InternalAuditStatus::Cancelled->value,
        ] as $terminalStatus) {
            $item = (new InternalAudit())
                ->setAuditNumber('AUD-X')
                ->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus}",
            );
        }
    }
}
