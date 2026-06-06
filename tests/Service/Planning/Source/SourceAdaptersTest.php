<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning\Source;

use App\Entity\AuditFinding;
use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\ManagementReview;
use App\Entity\Vulnerability;
use App\Enum\ChangeRequestStatus;
use App\Enum\IncidentStatus;
use App\Enum\InternalAuditStatus;
use App\Enum\ManagementReviewStatus;
use App\Enum\VulnerabilityStatus;
use App\Repository\AuditFindingRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\Planning\Source\Adapter\AuditFindingAdapter;
use App\Service\Planning\Source\Adapter\ChangeRequestAdapter;
use App\Service\Planning\Source\Adapter\CorrectiveActionAdapter;
use App\Service\Planning\Source\Adapter\DocumentReviewAdapter;
use App\Service\Planning\Source\Adapter\IncidentAdapter;
use App\Service\Planning\Source\Adapter\InternalAuditAdapter;
use App\Service\Planning\Source\Adapter\ManagementReviewAdapter;
use App\Service\Planning\Source\Adapter\VulnerabilityAdapter;
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

    // ──────────────────────────────────────────────────────────────────────────
    // IncidentAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testIncidentAdapterMeta(): void
    {
        $adapter = new IncidentAdapter(
            $this->createStub(IncidentRepository::class),
        );

        self::assertSame('incident', $adapter->slug());
        self::assertSame('Sicherheitsvorfall', $adapter->label());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testIncidentAdapterDueDateAndTitle(): void
    {
        $adapter = new IncidentAdapter(
            $this->createStub(IncidentRepository::class),
        );

        $date = new DateTimeImmutable('2026-08-15');
        $item = (new Incident())
            ->setTitle('Ransomware on file server')
            ->setDetectedAt($date)
            ->setStatus(IncidentStatus::InInvestigation);

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('Ransomware on file server', $adapter->titleOf($item));
    }

    public function testIncidentAdapterTitleFallback(): void
    {
        $adapter = new IncidentAdapter(
            $this->createStub(IncidentRepository::class),
        );

        $item = new Incident(); // title null, id null
        self::assertStringStartsWith('#', $adapter->titleOf($item));
    }

    public function testIncidentAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new IncidentAdapter(
            $this->createStub(IncidentRepository::class),
        );

        foreach ([
            IncidentStatus::Reported,
            IncidentStatus::InInvestigation,
            IncidentStatus::InResolution,
        ] as $openStatus) {
            $item = (new Incident())->setStatus($openStatus);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$openStatus->value}",
            );
        }
    }

    public function testIncidentAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new IncidentAdapter(
            $this->createStub(IncidentRepository::class),
        );

        foreach ([
            IncidentStatus::Resolved,
            IncidentStatus::Closed,
        ] as $terminalStatus) {
            $item = (new Incident())->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // VulnerabilityAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testVulnerabilityAdapterMeta(): void
    {
        $adapter = new VulnerabilityAdapter(
            $this->createStub(VulnerabilityRepository::class),
        );

        self::assertSame('vulnerability', $adapter->slug());
        self::assertSame('Schwachstelle', $adapter->label());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testVulnerabilityAdapterDueDateAndTitle(): void
    {
        $adapter = new VulnerabilityAdapter(
            $this->createStub(VulnerabilityRepository::class),
        );

        $deadline = new DateTimeImmutable('2026-07-01');
        $item = (new Vulnerability())
            ->setTitle('CVE-2026-99999 — critical RCE')
            ->setRemediationDeadline($deadline)
            ->setStatus(VulnerabilityStatus::Open);

        self::assertSame($deadline, $adapter->dueDateOf($item));
        self::assertSame('CVE-2026-99999 — critical RCE', $adapter->titleOf($item));
    }

    public function testVulnerabilityAdapterTitleFallback(): void
    {
        $adapter = new VulnerabilityAdapter(
            $this->createStub(VulnerabilityRepository::class),
        );

        $item = new Vulnerability(); // title null, id null
        self::assertStringStartsWith('#', $adapter->titleOf($item));
    }

    public function testVulnerabilityAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new VulnerabilityAdapter(
            $this->createStub(VulnerabilityRepository::class),
        );

        foreach ([
            VulnerabilityStatus::Open,
            VulnerabilityStatus::InTriage,
            VulnerabilityStatus::InRemediation,
        ] as $openStatus) {
            $item = (new Vulnerability())->setStatus($openStatus);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$openStatus->value}",
            );
        }
    }

    public function testVulnerabilityAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new VulnerabilityAdapter(
            $this->createStub(VulnerabilityRepository::class),
        );

        foreach ([
            VulnerabilityStatus::Patched,
            VulnerabilityStatus::Mitigated,
            VulnerabilityStatus::Accepted,
            VulnerabilityStatus::FalsePositive,
            VulnerabilityStatus::Closed,
        ] as $terminalStatus) {
            $item = (new Vulnerability())->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ChangeRequestAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testChangeRequestAdapterMeta(): void
    {
        $adapter = new ChangeRequestAdapter(
            $this->createStub(ChangeRequestRepository::class),
        );

        self::assertSame('change_request', $adapter->slug());
        self::assertSame('Change Request', $adapter->label());
        self::assertSame('change_requests', $adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testChangeRequestAdapterDueDateAndTitle(): void
    {
        $adapter = new ChangeRequestAdapter(
            $this->createStub(ChangeRequestRepository::class),
        );

        $date = new DateTimeImmutable('2026-09-30');
        $item = (new ChangeRequest())
            ->setTitle('Upgrade TLS 1.0 to TLS 1.3')
            ->setPlannedImplementationDate($date)
            ->setStatus(ChangeRequestStatus::Approved);

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('Upgrade TLS 1.0 to TLS 1.3', $adapter->titleOf($item));
    }

    public function testChangeRequestAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new ChangeRequestAdapter(
            $this->createStub(ChangeRequestRepository::class),
        );

        foreach ([
            ChangeRequestStatus::Draft,
            ChangeRequestStatus::Submitted,
            ChangeRequestStatus::UnderReview,
            ChangeRequestStatus::Approved,
            ChangeRequestStatus::Scheduled,
            ChangeRequestStatus::Implemented,
            ChangeRequestStatus::Verified,
        ] as $openStatus) {
            $item = (new ChangeRequest())->setStatus($openStatus);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$openStatus->value}",
            );
        }
    }

    public function testChangeRequestAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new ChangeRequestAdapter(
            $this->createStub(ChangeRequestRepository::class),
        );

        foreach ([
            ChangeRequestStatus::Closed,
            ChangeRequestStatus::Cancelled,
            ChangeRequestStatus::Rejected,
        ] as $terminalStatus) {
            $item = (new ChangeRequest())->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DocumentReviewAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testDocumentReviewAdapterMeta(): void
    {
        $adapter = new DocumentReviewAdapter(
            $this->createStub(DocumentRepository::class),
        );

        self::assertSame('document_review', $adapter->slug());
        self::assertSame('Dokumenten-Review', $adapter->label());
        self::assertSame('documents', $adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testDocumentReviewAdapterDueDateAndTitle(): void
    {
        $adapter = new DocumentReviewAdapter(
            $this->createStub(DocumentRepository::class),
        );

        $date = new DateTimeImmutable('2027-01-15');
        $item = (new Document())
            ->setNextReviewDate($date)
            ->setOriginalFilename('ISMS-Leitlinie-v3.pdf');

        self::assertSame($date, $adapter->dueDateOf($item));
        self::assertSame('ISMS-Leitlinie-v3.pdf', $adapter->titleOf($item));
    }

    public function testDocumentReviewAdapterDueDateNullWhenNotSet(): void
    {
        $adapter = new DocumentReviewAdapter(
            $this->createStub(DocumentRepository::class),
        );

        $item = new Document(); // nextReviewDate not set
        self::assertNull($adapter->dueDateOf($item));
    }

    public function testDocumentReviewAdapterTitleFallback(): void
    {
        $adapter = new DocumentReviewAdapter(
            $this->createStub(DocumentRepository::class),
        );

        $item = new Document(); // originalFilename null, id null
        self::assertStringStartsWith('#', $adapter->titleOf($item));
    }

    public function testDocumentReviewAdapterIsCompletedAlwaysFalse(): void
    {
        $adapter = new DocumentReviewAdapter(
            $this->createStub(DocumentRepository::class),
        );

        // Document review is perpetually recurring — isCompleted always false
        // regardless of document lifecycle status (draft, published, archived…)
        $item = new Document();
        self::assertFalse($adapter->isCompleted($item));
    }
}
