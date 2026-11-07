<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\DataBreach;
use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionType;
use App\Repository\DataBreachRepository;
use App\Repository\IncidentRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\ManagementDecisionRepository;
use App\Repository\TrainingParticipationRepository;
use App\Service\ComplianceWizard\ManagementDecisionCheck;
use App\Service\ComplianceWizard\RegisterCoverageCheck;
use App\Service\ComplianceWizard\ReportingDutyCheck;
use App\Service\ComplianceWizard\TrainingParticipationCheck;
use App\Service\Incident\ReportingPlanner;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wizard checks that read registers, top-management decisions, training
 * participation and reporting duties — and through RecordCheckRule the
 * fulfilment of ISO 27001 4.1, 5.1, 7.4, A.6.3, NIS-2 Art. 20 (1), Art. 23.
 */
final class RecordChecksTest extends TestCase
{
    #[Test]
    public function registerCoverageCountsCurrentEntriesPerRegister(): void
    {
        $entries = $this->createStub(IsmsRegisterEntryRepository::class);
        $entries->method('statsByType')->willReturn([
            'issue_external' => ['active' => 4, 'overdue' => 1, 'changed' => null],
        ]);
        $check = new RegisterCoverageCheck($entries);

        $result = $check->check(['registers' => ['issue_external', 'issue_internal']], new Tenant());

        self::assertSame(37.5, $result['score'], 'external 3 of 4 current (75), internal empty (0)');
        self::assertSame(1, $result['details']['overdue']);
        self::assertNotNull($result['gap']);
    }

    #[Test]
    public function aRegisterWithoutEntriesIsNoData(): void
    {
        $entries = $this->createStub(IsmsRegisterEntryRepository::class);
        $entries->method('statsByType')->willReturn([]);

        $result = (new RegisterCoverageCheck($entries))->check(['registers' => ['communication']], new Tenant());

        self::assertSame('no_data', $result['details']['reason']);
    }

    #[Test]
    public function managementDecisionScoresApprovalPendingAndExpiry(): void
    {
        $today = new DateTimeImmutable('2026-11-07');
        $decisions = $this->createStub(ManagementDecisionRepository::class);
        $decisions->method('findByTenant')->willReturn([
            $this->decision(ManagementDecisionType::Policy, 'approved', new DateTimeImmutable('2027-11-01')),
            $this->decision(ManagementDecisionType::Resources, 'pending'),
            $this->decision(ManagementDecisionType::Objectives, 'approved', new DateTimeImmutable('2026-10-01')),
        ]);
        $check = new ManagementDecisionCheck($decisions);

        $result = $check->check(['decision_types' => ['policy', 'resources', 'objectives']], new Tenant(), $today);

        self::assertSame(58.3, $result['score'], '(100 + 50 + 25) / 3');
        self::assertSame(1, $result['details']['effective']);
        self::assertSame(1, $result['details']['pending']);

        $none = $check->check(['decision_types' => ['nis2_measures']], new Tenant(), $today);
        self::assertSame('no_data', $none['details']['reason'], 'no NIS-2 decision recorded yet');
    }

    #[Test]
    public function trainingParticipationLeavesWaivedOut(): void
    {
        $participations = $this->createStub(TrainingParticipationRepository::class);
        $participations->method('countByStatus')->willReturn(['completed' => 6, 'pending' => 2, 'waived' => 2]);

        $result = (new TrainingParticipationCheck($participations))->check([], new Tenant());

        self::assertSame(75.0, $result['score']);
        self::assertSame(8, $result['details']['total']);
    }

    #[Test]
    public function reportingDutyJudgesDueReportsOnly(): void
    {
        $tenant = new Tenant();
        $now = new DateTimeImmutable('2026-11-07 12:00');
        $inTime = $this->breach($tenant, '2026-10-01 08:00', '2026-10-02 08:00');
        $late = $this->breach($tenant, '2026-10-10 08:00', '2026-10-20 08:00');
        $running = $this->breach($tenant, '2026-11-07 08:00', null);

        $incidents = $this->createStub(IncidentRepository::class);
        $incidents->method('findByTenant')->willReturn([]);
        $breaches = $this->createStub(DataBreachRepository::class);
        $breaches->method('findByTenant')->willReturn([$inTime, $late, $running]);
        $modules = $this->createStub(ModuleConfigurationService::class);
        $check = new ReportingDutyCheck(new ReportingPlanner(static fn (): ModuleConfigurationService => $modules), $incidents, $breaches);

        $result = $check->check(['deadline_types' => ['gdpr_72h']], $tenant, $now);

        self::assertSame(50.0, $result['score'], 'one of two due notifications in time, the running one is not judged');
        self::assertSame(2, $result['details']['total']);
        self::assertSame('no_data', $check->check(['deadline_types' => ['nis2_24h']], $tenant, $now)['details']['reason']);
    }

    private function decision(ManagementDecisionType $type, string $status, ?DateTimeImmutable $validUntil = null): ManagementDecision
    {
        return (new ManagementDecision())->setType($type)->setStatus($status)->setValidUntil($validUntil);
    }

    private function breach(Tenant $tenant, string $detected, ?string $notified): DataBreach
    {
        return (new DataBreach())
            ->setTenant($tenant)
            ->setDetectedAt(new DateTimeImmutable($detected))
            ->setRequiresAuthorityNotification(true)
            ->setSupervisoryAuthorityNotifiedAt($notified !== null ? new DateTimeImmutable($notified) : null);
    }
}
