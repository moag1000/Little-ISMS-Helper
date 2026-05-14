<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use App\Repository\ProcessingActivityRepository;
use App\Service\Authority\AuthorityHubService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuthorityHubService status aggregation.
 *
 * Covers: status per source (VVT, NIS-2, DORA RoI), summary counts,
 * hasOverdueObligation detection.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthorityHubServiceTest extends TestCase
{
    private Tenant $tenant;
    private Nis2RegistrationProfileRepository $nis2Repo;
    private DoraRegisterOfInformationRepository $doraRepo;
    private ProcessingActivityRepository $paRepo;

    protected function setUp(): void
    {
        $this->tenant  = new Tenant();
        $this->nis2Repo  = $this->createMock(Nis2RegistrationProfileRepository::class);
        $this->doraRepo  = $this->createMock(DoraRegisterOfInformationRepository::class);
        $this->paRepo    = $this->createMock(ProcessingActivityRepository::class);
    }

    private function makeService(): AuthorityHubService
    {
        return new AuthorityHubService($this->nis2Repo, $this->doraRepo, $this->paRepo);
    }

    #[Test]
    public function getReportingObligationsReturnsFourEntries(): void
    {
        $this->nis2Repo->method('findForTenant')->willReturn(null);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        self::assertCount(4, $obligations, 'Hub must aggregate exactly 4 obligation sources (F25/F26/F29/F30)');
    }

    #[Test]
    public function obligationsContainRequiredKeys(): void
    {
        $this->nis2Repo->method('findForTenant')->willReturn(null);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        foreach ($obligations as $obligation) {
            self::assertArrayHasKey('authority', $obligation);
            self::assertArrayHasKey('status', $obligation);
            self::assertArrayHasKey('exportUrl', $obligation);
            self::assertArrayHasKey('country', $obligation);
        }
    }

    #[Test]
    public function nis2OverdueProfileSetsOverdueStatus(): void
    {
        $profile = $this->createMock(Nis2RegistrationProfile::class);
        $profile->method('isOverdue')->willReturn(true);
        $profile->method('isDueSoon')->willReturn(false);
        $profile->method('getNextDueAt')->willReturn(new DateTimeImmutable('-10 days'));
        $profile->method('getLastReportedAt')->willReturn(null);

        $this->nis2Repo->method('findForTenant')->willReturn($profile);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        $nis2Obligation = $this->findObligation($obligations, 'bsi_nis2');
        self::assertNotNull($nis2Obligation);
        self::assertSame('overdue', $nis2Obligation['status']);
    }

    #[Test]
    public function nis2DueSoonProfileSetsDueSoonStatus(): void
    {
        $profile = $this->createMock(Nis2RegistrationProfile::class);
        $profile->method('isOverdue')->willReturn(false);
        $profile->method('isDueSoon')->willReturn(true);
        $profile->method('getNextDueAt')->willReturn(new DateTimeImmutable('+15 days'));
        $profile->method('getLastReportedAt')->willReturn(null);

        $this->nis2Repo->method('findForTenant')->willReturn($profile);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        $nis2Obligation = $this->findObligation($obligations, 'bsi_nis2');
        self::assertNotNull($nis2Obligation);
        self::assertSame('due_soon', $nis2Obligation['status']);
    }

    #[Test]
    public function noNis2ProfileSetsNotConfiguredStatus(): void
    {
        $this->nis2Repo->method('findForTenant')->willReturn(null);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        $nis2Obligation = $this->findObligation($obligations, 'bsi_nis2');
        self::assertNotNull($nis2Obligation);
        self::assertSame('not_configured', $nis2Obligation['status']);
    }

    #[Test]
    public function submittedDoraRecordSetsCurrentStatus(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setSubmittedAt(new DateTimeImmutable());

        $this->nis2Repo->method('findForTenant')->willReturn(null);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn($record);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        $doraObligation = $this->findObligation($obligations, 'dora_roi');
        self::assertNotNull($doraObligation);
        self::assertSame('current', $doraObligation['status']);
    }

    #[Test]
    public function getStatusSummaryReturnsCorrectCounts(): void
    {
        $profile = $this->createMock(Nis2RegistrationProfile::class);
        $profile->method('isOverdue')->willReturn(true);
        $profile->method('isDueSoon')->willReturn(false);
        $profile->method('getNextDueAt')->willReturn(new DateTimeImmutable('-5 days'));
        $profile->method('getLastReportedAt')->willReturn(null);

        $this->nis2Repo->method('findForTenant')->willReturn($profile);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        $summary = $service->getStatusSummary($this->tenant);

        self::assertArrayHasKey('overdue', $summary);
        self::assertArrayHasKey('current', $summary);
        self::assertArrayHasKey('due_soon', $summary);
        self::assertGreaterThanOrEqual(1, $summary['overdue'], 'At least the NIS-2 obligation must be overdue');
    }

    #[Test]
    public function hasOverdueObligationReturnsTrueWhenNis2IsOverdue(): void
    {
        $profile = $this->createMock(Nis2RegistrationProfile::class);
        $profile->method('isOverdue')->willReturn(true);
        $profile->method('isDueSoon')->willReturn(false);
        $profile->method('getNextDueAt')->willReturn(new DateTimeImmutable('-5 days'));
        $profile->method('getLastReportedAt')->willReturn(null);

        $this->nis2Repo->method('findForTenant')->willReturn($profile);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        self::assertTrue($service->hasOverdueObligation($this->tenant));
    }

    #[Test]
    public function hasOverdueObligationReturnsFalseWhenAllCurrent(): void
    {
        $profile = $this->createMock(Nis2RegistrationProfile::class);
        $profile->method('isOverdue')->willReturn(false);
        $profile->method('isDueSoon')->willReturn(false);
        $profile->method('getNextDueAt')->willReturn(new DateTimeImmutable('+300 days'));
        $profile->method('getLastReportedAt')->willReturn(new DateTimeImmutable());

        $doraRecord = new DoraRegisterOfInformation();
        $doraRecord->setSubmittedAt(new DateTimeImmutable());

        $this->nis2Repo->method('findForTenant')->willReturn($profile);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn($doraRecord);
        $this->paRepo->method('findByTenant')->willReturn([]);

        $service = $this->makeService();
        self::assertFalse($service->hasOverdueObligation($this->tenant));
    }

    #[Test]
    public function vvtObligationIsAvailableWhenProcessingActivitiesExist(): void
    {
        $this->nis2Repo->method('findForTenant')->willReturn(null);
        $this->doraRepo->method('findCurrentYearForTenant')->willReturn(null);
        // Simulate 3 processing activities
        $this->paRepo->method('findByTenant')->willReturn([new \stdClass(), new \stdClass(), new \stdClass()]);

        $service = $this->makeService();
        $obligations = $service->getReportingObligationsForTenant($this->tenant);

        $vvtObligation = $this->findObligation($obligations, 'vvt_bfdi');
        self::assertNotNull($vvtObligation);
        self::assertSame('available', $vvtObligation['status']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $obligations
     * @return array<string, mixed>|null
     */
    private function findObligation(array $obligations, string $authority): ?array
    {
        foreach ($obligations as $o) {
            if ($o['authority'] === $authority) {
                return $o;
            }
        }
        return null;
    }
}
