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
use App\Entity\Tenant;
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
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Risk;
use App\Entity\TrainingParticipation;
use App\Entity\WorkflowInstance;
use App\Enum\DataBreachStatus;
use App\Enum\DpiaStatus;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingParticipationRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Planning\Source\Adapter\AuditFindingAdapter;
use App\Service\Planning\Source\Adapter\ChangeRequestAdapter;
use App\Service\Planning\Source\Adapter\CorrectiveActionAdapter;
use App\Service\Planning\Source\Adapter\DataBreachAdapter;
use App\Service\Planning\Source\Adapter\DataSubjectRequestAdapter;
use App\Service\Planning\Source\Adapter\DocumentReviewAdapter;
use App\Service\Planning\Source\Adapter\DpiaAdapter;
use App\Service\Planning\Source\Adapter\FourEyesAdapter;
use App\Service\Planning\Source\Adapter\IncidentAdapter;
use App\Service\Planning\Source\Adapter\InternalAuditAdapter;
use App\Service\Planning\Source\Adapter\ManagementReviewAdapter;
use App\Service\Planning\Source\Adapter\PolicyAckAdapter;
use App\Service\Planning\Source\Adapter\RiskTreatmentAdapter;
use App\Service\Planning\Source\Adapter\TrainingParticipationAdapter;
use App\Service\Planning\Source\Adapter\VulnerabilityAdapter;
use App\Service\Planning\Source\Adapter\WorkflowInstanceAdapter;
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

    // ──────────────────────────────────────────────────────────────────────────
    // RiskTreatmentAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testRiskTreatmentAdapterMeta(): void
    {
        $adapter = new RiskTreatmentAdapter(
            $this->createStub(RiskRepository::class),
        );

        self::assertSame('risk_treatment', $adapter->slug());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testRiskTreatmentAdapterDueDateAndTitle(): void
    {
        $adapter = new RiskTreatmentAdapter(
            $this->createStub(RiskRepository::class),
        );

        $expiry = new DateTimeImmutable('2027-01-31');
        $item = (new Risk())
            ->setTitle('Outdated SaaS contract')
            ->setAcceptanceExpiryDate($expiry)
            ->setTreatmentStrategy(TreatmentStrategy::Accept)
            ->setStatus(RiskStatus::Accepted);

        self::assertSame($expiry, $adapter->dueDateOf($item));
        self::assertSame('Outdated SaaS contract', $adapter->titleOf($item));
    }

    public function testRiskTreatmentAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new RiskTreatmentAdapter(
            $this->createStub(RiskRepository::class),
        );

        foreach ([
            RiskStatus::Identified,
            RiskStatus::Assessed,
            RiskStatus::InTreatment,
            RiskStatus::Accepted,
        ] as $openStatus) {
            $item = (new Risk())->setStatus($openStatus);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$openStatus->value}",
            );
        }
    }

    public function testRiskTreatmentAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new RiskTreatmentAdapter(
            $this->createStub(RiskRepository::class),
        );

        foreach ([RiskStatus::Closed, RiskStatus::Monitored] as $terminalStatus) {
            $item = (new Risk())->setStatus($terminalStatus);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminalStatus->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DataSubjectRequestAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testDsrAdapterMeta(): void
    {
        $adapter = new DataSubjectRequestAdapter(
            $this->createStub(DataSubjectRequestRepository::class),
        );

        self::assertSame('dsr', $adapter->slug());
        self::assertSame('privacy', $adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testDsrAdapterDueDateAndTitle(): void
    {
        $adapter = new DataSubjectRequestAdapter(
            $this->createStub(DataSubjectRequestRepository::class),
        );

        $deadline = new DateTimeImmutable('2026-09-30');
        $item = (new DataSubjectRequest())
            ->setRequestType('access')
            ->setDeadlineAt($deadline);

        self::assertSame($deadline, $adapter->dueDateOf($item));
        self::assertStringContainsString('access', $adapter->titleOf($item));
    }

    public function testDsrAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new DataSubjectRequestAdapter(
            $this->createStub(DataSubjectRequestRepository::class),
        );

        foreach (['received', 'identity_verification', 'in_progress', 'extended'] as $open) {
            $item = (new DataSubjectRequest())->setStatus($open);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$open}",
            );
        }
    }

    public function testDsrAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new DataSubjectRequestAdapter(
            $this->createStub(DataSubjectRequestRepository::class),
        );

        foreach (['completed', 'rejected'] as $terminal) {
            $item = (new DataSubjectRequest())->setStatus($terminal);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminal}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DataBreachAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testDataBreachAdapterMeta(): void
    {
        $adapter = new DataBreachAdapter(
            $this->createStub(DataBreachRepository::class),
        );

        self::assertSame('data_breach', $adapter->slug());
        self::assertSame('privacy', $adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testDataBreachAdapterDueDateDerived72h(): void
    {
        $adapter = new DataBreachAdapter(
            $this->createStub(DataBreachRepository::class),
        );

        $detectedAt = new DateTimeImmutable('2026-10-01 10:00:00');
        $item = (new DataBreach())
            ->setTitle('Email leak')
            ->setReferenceNumber('BREACH-2026-001')
            ->setDetectedAt($detectedAt);

        $due = $adapter->dueDateOf($item);
        self::assertNotNull($due);
        // Derived: +72 hours = 2026-10-04 10:00:00
        self::assertSame('2026-10-04 10:00:00', $due->format('Y-m-d H:i:s'));
    }

    public function testDataBreachAdapterDueDateIsSetOnFreshEntity(): void
    {
        $adapter = new DataBreachAdapter(
            $this->createStub(DataBreachRepository::class),
        );

        // DataBreach constructor pre-fills detectedAt = now, so dueDateOf is never null
        $item = new DataBreach();
        // The due date is +72h from detectedAt (which is auto-set in constructor)
        self::assertNotNull($adapter->dueDateOf($item));
    }

    public function testDataBreachAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new DataBreachAdapter(
            $this->createStub(DataBreachRepository::class),
        );

        foreach ([DataBreachStatus::Draft, DataBreachStatus::UnderAssessment] as $open) {
            $item = (new DataBreach())->setStatus($open);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$open->value}",
            );
        }
    }

    public function testDataBreachAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new DataBreachAdapter(
            $this->createStub(DataBreachRepository::class),
        );

        foreach ([
            DataBreachStatus::AuthorityNotified,
            DataBreachStatus::SubjectsNotified,
            DataBreachStatus::Closed,
        ] as $terminal) {
            $item = (new DataBreach())->setStatus($terminal);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminal->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PolicyAckAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testPolicyAckAdapterMeta(): void
    {
        $adapter = new PolicyAckAdapter(
            $this->createStub(PolicyAcknowledgementRepository::class),
        );

        self::assertSame('policy_ack', $adapter->slug());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testPolicyAckAdapterDueDateAlwaysNull(): void
    {
        $adapter = new PolicyAckAdapter(
            $this->createStub(PolicyAcknowledgementRepository::class),
        );

        $item = new PolicyAcknowledgement();
        self::assertNull($adapter->dueDateOf($item));
    }

    public function testPolicyAckAdapterIsCompletedFalseForPending(): void
    {
        $adapter = new PolicyAckAdapter(
            $this->createStub(PolicyAcknowledgementRepository::class),
        );

        $item = (new PolicyAcknowledgement())->setStatus(PolicyAcknowledgement::STATUS_PENDING);
        self::assertFalse($adapter->isCompleted($item));
    }

    public function testPolicyAckAdapterIsCompletedTrueForAcknowledged(): void
    {
        $adapter = new PolicyAckAdapter(
            $this->createStub(PolicyAcknowledgementRepository::class),
        );

        $item = (new PolicyAcknowledgement())->setStatus(PolicyAcknowledgement::STATUS_ACKNOWLEDGED);
        self::assertTrue($adapter->isCompleted($item));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TrainingParticipationAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testTrainingParticipationAdapterMeta(): void
    {
        $adapter = new TrainingParticipationAdapter(
            $this->createStub(TrainingParticipationRepository::class),
        );

        self::assertSame('training', $adapter->slug());
        self::assertSame('training', $adapter->requiredModule());
        self::assertTrue($adapter->ownsRecurrence());
    }

    public function testTrainingParticipationAdapterDueDateAndTitle(): void
    {
        $adapter = new TrainingParticipationAdapter(
            $this->createStub(TrainingParticipationRepository::class),
        );

        $item = new TrainingParticipation();
        $assigned = $item->getAssignedAt(); // set in constructor
        self::assertSame($assigned, $adapter->dueDateOf($item));
    }

    public function testTrainingParticipationAdapterIsCompletedFalseForOpen(): void
    {
        $adapter = new TrainingParticipationAdapter(
            $this->createStub(TrainingParticipationRepository::class),
        );

        foreach ([
            TrainingParticipation::STATUS_PENDING,
            TrainingParticipation::STATUS_IN_PROGRESS,
        ] as $open) {
            $item = (new TrainingParticipation())->setStatus($open);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$open}",
            );
        }
    }

    public function testTrainingParticipationAdapterIsCompletedTrueForTerminal(): void
    {
        $adapter = new TrainingParticipationAdapter(
            $this->createStub(TrainingParticipationRepository::class),
        );

        foreach ([
            TrainingParticipation::STATUS_COMPLETED,
            TrainingParticipation::STATUS_FAILED,
            TrainingParticipation::STATUS_WAIVED,
        ] as $terminal) {
            $item = (new TrainingParticipation())->setStatus($terminal);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminal}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // WorkflowInstanceAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testWorkflowInstanceAdapterMeta(): void
    {
        $adapter = new WorkflowInstanceAdapter(
            $this->createStub(WorkflowInstanceRepository::class),
        );

        self::assertSame('workflow', $adapter->slug());
        self::assertSame('workflows', $adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testWorkflowInstanceAdapterDueDateAndTitle(): void
    {
        $adapter = new WorkflowInstanceAdapter(
            $this->createStub(WorkflowInstanceRepository::class),
        );

        $due = new DateTimeImmutable('2026-11-15');
        $item = (new WorkflowInstance())
            ->setDueDate($due)
            ->setStatus('pending');

        self::assertSame($due, $adapter->dueDateOf($item));
        self::assertStringStartsWith('Workflow #', $adapter->titleOf($item));
    }

    public function testWorkflowInstanceAdapterIsCompletedFalseForOpen(): void
    {
        $adapter = new WorkflowInstanceAdapter(
            $this->createStub(WorkflowInstanceRepository::class),
        );

        foreach ([
            WorkflowInstanceStatus::Pending,
            WorkflowInstanceStatus::InProgress,
        ] as $open) {
            $item = (new WorkflowInstance())->setStatus($open->value);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$open->value}",
            );
        }
    }

    public function testWorkflowInstanceAdapterIsCompletedTrueForTerminal(): void
    {
        $adapter = new WorkflowInstanceAdapter(
            $this->createStub(WorkflowInstanceRepository::class),
        );

        foreach ([
            WorkflowInstanceStatus::Approved,
            WorkflowInstanceStatus::Rejected,
            WorkflowInstanceStatus::Cancelled,
        ] as $terminal) {
            $item = (new WorkflowInstance())->setStatus($terminal->value);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminal->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FourEyesAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testFourEyesAdapterMeta(): void
    {
        $adapter = new FourEyesAdapter(
            $this->createStub(FourEyesApprovalRequestRepository::class),
        );

        self::assertSame('four_eyes', $adapter->slug());
        self::assertNull($adapter->requiredModule());
        self::assertFalse($adapter->ownsRecurrence());
    }

    public function testFourEyesAdapterDueDateAndTitle(): void
    {
        $adapter = new FourEyesAdapter(
            $this->createStub(FourEyesApprovalRequestRepository::class),
        );

        $item = new FourEyesApprovalRequest(); // expiresAt set in constructor to +7 days
        $due = $adapter->dueDateOf($item);
        self::assertNotNull($due);
        self::assertSame(FourEyesApprovalRequest::ACTION_INHERITANCE_IMPLEMENT, $adapter->titleOf(
            (new FourEyesApprovalRequest())->setActionType(FourEyesApprovalRequest::ACTION_INHERITANCE_IMPLEMENT),
        ));
    }

    public function testFourEyesAdapterIsCompletedFalseForPending(): void
    {
        $adapter = new FourEyesAdapter(
            $this->createStub(FourEyesApprovalRequestRepository::class),
        );

        $item = new FourEyesApprovalRequest(); // default status = pending
        self::assertFalse($adapter->isCompleted($item));
    }

    public function testFourEyesAdapterIsCompletedTrueForNonPending(): void
    {
        $adapter = new FourEyesAdapter(
            $this->createStub(FourEyesApprovalRequestRepository::class),
        );

        foreach ([
            FourEyesApprovalRequest::STATUS_APPROVED,
            FourEyesApprovalRequest::STATUS_REJECTED,
            FourEyesApprovalRequest::STATUS_EXPIRED,
        ] as $terminal) {
            $item = (new FourEyesApprovalRequest())->setStatus($terminal);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminal}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DpiaAdapter
    // ──────────────────────────────────────────────────────────────────────────

    public function testDpiaAdapterMeta(): void
    {
        $adapter = new DpiaAdapter(
            $this->createStub(DataProtectionImpactAssessmentRepository::class),
        );

        self::assertSame('dpia', $adapter->slug());
        self::assertSame('privacy', $adapter->requiredModule());
        self::assertTrue($adapter->ownsRecurrence());
    }

    public function testDpiaAdapterDueDateAndTitle(): void
    {
        $adapter = new DpiaAdapter(
            $this->createStub(DataProtectionImpactAssessmentRepository::class),
        );

        $nextReview = new DateTimeImmutable('2027-03-01');
        $item = (new DataProtectionImpactAssessment())
            ->setTitle('DPIA — Customer Analytics')
            ->setReferenceNumber('DPIA-2026-001')
            ->setNextReviewDate($nextReview);

        self::assertSame($nextReview, $adapter->dueDateOf($item));
        self::assertSame('DPIA — Customer Analytics', $adapter->titleOf($item));
    }

    public function testDpiaAdapterIsCompletedFalseForOpenStatuses(): void
    {
        $adapter = new DpiaAdapter(
            $this->createStub(DataProtectionImpactAssessmentRepository::class),
        );

        foreach ([
            DpiaStatus::Draft,
            DpiaStatus::InReview,
            DpiaStatus::RequiresRevision,
        ] as $open) {
            $item = (new DataProtectionImpactAssessment())
                ->setReferenceNumber('DPIA-X')
                ->setStatus($open->value);
            self::assertFalse(
                $adapter->isCompleted($item),
                "Expected isCompleted=false for status={$open->value}",
            );
        }
    }

    public function testDpiaAdapterIsCompletedTrueForTerminalStatuses(): void
    {
        $adapter = new DpiaAdapter(
            $this->createStub(DataProtectionImpactAssessmentRepository::class),
        );

        foreach ([DpiaStatus::Approved, DpiaStatus::Rejected] as $terminal) {
            $item = (new DataProtectionImpactAssessment())
                ->setReferenceNumber('DPIA-X')
                ->setStatus($terminal->value);
            self::assertTrue(
                $adapter->isCompleted($item),
                "Expected isCompleted=true for status={$terminal->value}",
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // findConvertible() delegation tests (new focused repo methods)
    // ──────────────────────────────────────────────────────────────────────────

    public function testDataBreachAdapterFindConvertibleDelegatesToFocusedMethod(): void
    {
        $tenant = new Tenant();
        $open   = (new DataBreach())->setStatus(DataBreachStatus::Draft);
        $closed = (new DataBreach())->setStatus(DataBreachStatus::Closed);

        $repo = $this->createMock(DataBreachRepository::class);
        $repo->expects(self::once())
            ->method('findConvertibleForTenant')
            ->with($tenant)
            ->willReturn([$open]);

        $adapter = new DataBreachAdapter($repo);
        $result  = iterator_to_array($adapter->findConvertible($tenant));

        self::assertSame([$open], $result);
        self::assertNotContains($closed, $result);
    }

    public function testDsrAdapterFindConvertibleDelegatesToFocusedMethod(): void
    {
        $tenant = new Tenant();
        $open   = (new DataSubjectRequest())->setStatus('in_progress');
        $done   = (new DataSubjectRequest())->setStatus('completed');

        $repo = $this->createMock(DataSubjectRequestRepository::class);
        $repo->expects(self::once())
            ->method('findConvertibleForTenant')
            ->with($tenant)
            ->willReturn([$open]);

        $adapter = new DataSubjectRequestAdapter($repo);
        $result  = iterator_to_array($adapter->findConvertible($tenant));

        self::assertSame([$open], $result);
        self::assertNotContains($done, $result);
    }

    public function testDpiaAdapterFindConvertibleDelegatesToFocusedMethod(): void
    {
        $tenant = new Tenant();
        $open   = (new DataProtectionImpactAssessment())
            ->setReferenceNumber('DPIA-1')
            ->setStatus(DpiaStatus::Draft->value);
        $done   = (new DataProtectionImpactAssessment())
            ->setReferenceNumber('DPIA-2')
            ->setStatus(DpiaStatus::Approved->value);

        $repo = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $repo->expects(self::once())
            ->method('findConvertibleForTenant')
            ->with($tenant)
            ->willReturn([$open]);

        $adapter = new DpiaAdapter($repo);
        $result  = iterator_to_array($adapter->findConvertible($tenant));

        self::assertSame([$open], $result);
        self::assertNotContains($done, $result);
    }

    public function testWorkflowInstanceAdapterFindConvertibleDelegatesToFindActiveForTenant(): void
    {
        $tenant  = new Tenant();
        $active  = (new WorkflowInstance())->setStatus(WorkflowInstanceStatus::Pending->value);
        $done    = (new WorkflowInstance())->setStatus(WorkflowInstanceStatus::Approved->value);

        $repo = $this->createMock(WorkflowInstanceRepository::class);
        $repo->expects(self::once())
            ->method('findActiveForTenant')
            ->with($tenant)
            ->willReturn([$active]);

        $adapter = new WorkflowInstanceAdapter($repo);
        $result  = iterator_to_array($adapter->findConvertible($tenant));

        self::assertSame([$active], $result);
        self::assertNotContains($done, $result);
    }

    public function testTrainingParticipationAdapterFindConvertibleDelegatesToFocusedMethod(): void
    {
        $tenant  = new Tenant();
        $pending = (new TrainingParticipation())->setStatus(TrainingParticipation::STATUS_PENDING);
        $done    = (new TrainingParticipation())->setStatus(TrainingParticipation::STATUS_COMPLETED);

        $repo = $this->createMock(TrainingParticipationRepository::class);
        $repo->expects(self::once())
            ->method('findConvertibleForTenant')
            ->with($tenant)
            ->willReturn([$pending]);

        $adapter = new TrainingParticipationAdapter($repo);
        $result  = iterator_to_array($adapter->findConvertible($tenant));

        self::assertSame([$pending], $result);
        self::assertNotContains($done, $result);
    }

    public function testRiskTreatmentAdapterFindConvertibleDelegatesToFocusedMethod(): void
    {
        $tenant    = new Tenant();
        $expiry    = new DateTimeImmutable('2027-06-30');
        $eligible  = (new Risk())
            ->setTreatmentStrategy(TreatmentStrategy::Accept)
            ->setAcceptanceExpiryDate($expiry)
            ->setStatus(RiskStatus::Accepted);
        $closed    = (new Risk())->setStatus(RiskStatus::Closed);

        $repo = $this->createMock(RiskRepository::class);
        $repo->expects(self::once())
            ->method('findConvertibleForTenant')
            ->with($tenant)
            ->willReturn([$eligible]);

        $adapter = new RiskTreatmentAdapter($repo);
        $result  = iterator_to_array($adapter->findConvertible($tenant));

        self::assertSame([$eligible], $result);
        self::assertNotContains($closed, $result);
    }
}
