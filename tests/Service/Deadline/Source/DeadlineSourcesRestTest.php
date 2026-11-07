<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline\Source;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\ChangeRequest;
use App\Entity\DoraExitPlan;
use App\Entity\Tenant;
use App\Entity\ThreatLedPenetrationTest;
use App\Enum\ManagementDecisionType;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\ManagementDecisionRepository;
use App\Service\Deadline\Source\ChangeApprovalSource;
use App\Service\Deadline\Source\DoraExitPlanTestSource;
use App\Service\Deadline\Source\Nis2RegistrationSource;
use App\Service\Deadline\Source\RetentionDeletionSource;
use App\Service\Deadline\Source\ThreatLedPenetrationTestSource;
use App\Service\ManagementDecision\ManagementDecisionService;
use App\Service\ManagementDecision\StandingApprovalSource;
use App\Service\Planning\Source\Adapter\ChangeRequestAdapter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * P2 "Jede Pflicht mit Datum erreicht jemanden": due dates and completion of
 * the sources that are more than one date field.
 */
final class DeadlineSourcesRestTest extends TestCase
{
    #[Test]
    public function nis2RegistrationIsDueAtOnceWithoutAProfile(): void
    {
        $tenant = (new Tenant())->setNis2Classification(Tenant::NIS2_IMPORTANT);
        $source = $this->nis2Source(null);

        $items = [...$source->findOpen($tenant)];

        self::assertCount(1, $items);
        self::assertLessThanOrEqual(new DateTimeImmutable('+1 minute'), $source->dueDateOf($items[0]));
    }

    #[Test]
    public function nis2RegistrationRecordedOnTheOrganisationCountsAsLastReport(): void
    {
        $tenant = (new Tenant())->setNis2RegisteredAt(new DateTimeImmutable('2026-03-01'));

        $profile = Nis2RegistrationProfile::forTenant($tenant);

        self::assertSame('2026-03-01', $profile->getLastReportedAt()?->format('Y-m-d'));
        self::assertSame('2027-03-01', $profile->getNextDueAt()->format('Y-m-d'));
    }

    #[Test]
    public function notRegulatedOrganisationsHaveNoRegistrationDuty(): void
    {
        $tenant = (new Tenant())->setNis2Classification(Tenant::NIS2_NOT_REGULATED);

        self::assertSame([], [...$this->nis2Source(null)->findOpen($tenant)]);
    }

    #[Test]
    public function aChangeBelongsToOneSourceAtATime(): void
    {
        $approval = new ChangeApprovalSource($this->createStub(EntityManagerInterface::class), $this->createStub(ChangeRequestRepository::class));
        $implementation = new ChangeRequestAdapter($this->createStub(ChangeRequestRepository::class));
        $change = new ChangeRequest();

        $change->setStatus('submitted');
        self::assertTrue($approval->supports($change));
        self::assertFalse($implementation->supports($change));

        $change->setStatus('approved');
        self::assertFalse($approval->supports($change));
        self::assertTrue($implementation->supports($change));
    }

    #[Test]
    public function aClosedTlptAsksForTheNextOneThreeYearsLater(): void
    {
        $source = new ThreatLedPenetrationTestSource($this->createStub(EntityManagerInterface::class));
        $test = new ThreatLedPenetrationTest();
        $test->setPlannedEndDate(new DateTimeImmutable('2026-05-01'));
        self::assertSame('2026-05-01', $source->dueDateOf($test)?->format('Y-m-d'));

        $test->setStatus(ThreatLedPenetrationTest::STATUS_CLOSED);
        $test->setActualEndDate(new DateTimeImmutable('2026-06-15'));
        self::assertSame('2029-06-15', $source->dueDateOf($test)?->format('Y-m-d'));
    }

    #[Test]
    public function exitPlansAreTestedYearly(): void
    {
        $source = new DoraExitPlanTestSource($this->createStub(EntityManagerInterface::class));
        $plan = new DoraExitPlan();
        $plan->setTestedAt(new DateTimeImmutable('2026-02-10'));

        self::assertSame('2027-02-10', $source->dueDateOf($plan)?->format('Y-m-d'));
    }

    #[Test]
    public function retentionDeletionIsDueAtTheNextSundaySweep(): void
    {
        // Wednesday → the coming Sunday 04:00; Sunday 05:00 → one week later.
        self::assertSame('2026-11-08 04:00', RetentionDeletionSource::nextSweep(new DateTimeImmutable('2026-11-04 12:00'))->format('Y-m-d H:i'));
        self::assertSame('2026-11-15 04:00', RetentionDeletionSource::nextSweep(new DateTimeImmutable('2026-11-08 05:00'))->format('Y-m-d H:i'));
    }

    #[Test]
    public function missingStandingApprovalsAreDueNowForTheIsb(): void
    {
        $repository = $this->createStub(ManagementDecisionRepository::class);
        $repository->method('findPending')->willReturn([]);
        $repository->method('findApprovalsEndingBefore')->willReturn([]);
        $decisions = $this->createStub(ManagementDecisionService::class);
        $decisions->method('currentApproval')->willReturn(null);
        $decisions->method('criteriaApproval')->willReturn(null);
        $source = new StandingApprovalSource($repository, $decisions, $this->createStub(TranslatorInterface::class));

        $items = [...$source->findOpen(new Tenant())];

        self::assertSame(StandingApprovalSource::STANDING, array_map(static fn ($i) => $i->type, $items));
        self::assertContains(ManagementDecisionType::Resources, $source->missing(new Tenant()));
        self::assertSame(['ROLE_ISB', 'ROLE_CISO'], $source->responsibleOf($items[0])->roles);
    }

    private function nis2Source(?Nis2RegistrationProfile $stored): Nis2RegistrationSource
    {
        $profiles = $this->createStub(Nis2RegistrationProfileRepository::class);
        $profiles->method('findForTenant')->willReturn($stored);

        return new Nis2RegistrationSource($this->createStub(EntityManagerInterface::class), $profiles);
    }
}
