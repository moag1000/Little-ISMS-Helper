<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\MfaTokenRepository;
use App\Repository\PatchRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Repository\UserRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\Nis2ComplianceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nis2ComplianceService unit tests.
 *
 * Verifies the directive-correct 10-letter grid (Art. 21(2)(a)-(j)) after the
 * Option B refactor. No legacy 21.2.k should appear; all 10 letters must be
 * present with the correct directive semantics.
 */
class Nis2ComplianceServiceTest extends TestCase
{
    private Nis2ComplianceService $service;

    protected function setUp(): void
    {
        // Stub required repositories with minimal mocks
        $incidentRepo = $this->createMock(IncidentRepository::class);
        $incidentRepo->method('count')->willReturn(0);
        $incidentRepo->method('findBy')->willReturn([]);

        $mfaRepo = $this->createMock(MfaTokenRepository::class);
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
        $query->method('getSingleScalarResult')->willReturn(0);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $mfaRepo->method('createQueryBuilder')->willReturn($qb);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('count')->willReturn(0);
        $userRepo->method('findBy')->willReturn([]);

        $vulnRepo = $this->createMock(VulnerabilityRepository::class);
        $vulnRepo->method('count')->willReturn(0);

        $patchRepo = $this->createMock(PatchRepository::class);
        $patchRepo->method('count')->willReturn(0);

        $this->service = new Nis2ComplianceService(
            incidentRepository: $incidentRepo,
            mfaTokenRepository: $mfaRepo,
            userRepository: $userRepo,
            vulnerabilityRepository: $vulnRepo,
            patchRepository: $patchRepo,
            // All optional dependencies null → metric returns 'na' or 'info'
        );
    }

    #[Test]
    public function getDashboardPayloadReturnsExactlyTenLetters(): void
    {
        $payload = $this->service->getDashboardPayload();

        $this->assertArrayHasKey('letters', $payload);
        $letters = $payload['letters'];
        $this->assertCount(10, $letters, 'Exactly 10 directive letters (a..j), no k');
    }

    #[Test]
    public function getDashboardPayloadKeysMatchDirectiveOrderAtoJ(): void
    {
        $payload = $this->service->getDashboardPayload();
        $keys = array_keys($payload['letters']);

        $expectedKeys = ['21.2.a', '21.2.b', '21.2.c', '21.2.d', '21.2.e', '21.2.f', '21.2.g', '21.2.h', '21.2.i', '21.2.j'];
        $this->assertSame($expectedKeys, $keys, 'Keys must follow directive Art. 21(2)(a)-(j) order');
    }

    #[Test]
    public function getDashboardPayloadDoesNotContainLegacyKeyK(): void
    {
        $payload = $this->service->getDashboardPayload();

        $this->assertArrayNotHasKey('21.2.k', $payload['letters'], '21.2.k does not exist in the NIS2 Directive');
    }

    #[Test]
    public function eachLetterHasRequiredShape(): void
    {
        $payload = $this->service->getDashboardPayload();

        foreach ($payload['letters'] as $key => $metric) {
            $this->assertArrayHasKey('letter', $metric, "Missing 'letter' key in $key");
            $this->assertArrayHasKey('title', $metric, "Missing 'title' key in $key");
            $this->assertArrayHasKey('status', $metric, "Missing 'status' key in $key");
            $this->assertArrayHasKey('details', $metric, "Missing 'details' key in $key");
            $this->assertSame($key, $metric['letter'], "Letter field must match array key for $key");
            $this->assertContains($metric['status'], ['good', 'warning', 'danger', 'info', 'na'],
                "Status value '$metric[status]' not in allowed set for $key");
        }
    }

    #[Test]
    public function letterBIsIncidentHandlingNotMfa(): void
    {
        $payload = $this->service->getDashboardPayload();

        $b = $payload['letters']['21.2.b'];
        $this->assertStringContainsStringIgnoringCase('incident', $b['title'],
            '21.2.b must be incident handling (directive b), not MFA');
    }

    #[Test]
    public function letterJIsMfaNotIncidentHandling(): void
    {
        $payload = $this->service->getDashboardPayload();

        $j = $payload['letters']['21.2.j'];
        $this->assertStringContainsStringIgnoringCase('mfa', $j['title'],
            '21.2.j must be MFA/secure comms (directive j), not incident handling');
    }

    #[Test]
    public function letterCIsBusinessContinuityNotEncryption(): void
    {
        $payload = $this->service->getDashboardPayload();

        $c = $payload['letters']['21.2.c'];
        $this->assertStringContainsStringIgnoringCase('continuity', $c['title'],
            '21.2.c must be business continuity (directive c), not encryption');
    }

    #[Test]
    public function letterHIsCryptographyNotAccessControl(): void
    {
        $payload = $this->service->getDashboardPayload();

        $h = $payload['letters']['21.2.h'];
        $this->assertStringContainsStringIgnoringCase('crypt', $h['title'],
            '21.2.h must be cryptography (directive h), not access control');
    }

    #[Test]
    public function overallScoreContainsExactlyTenKeys(): void
    {
        $payload = $this->service->getDashboardPayload();

        $this->assertArrayHasKey('overall', $payload);
        $overall = $payload['overall'];
        $this->assertArrayHasKey('score', $overall);
        $this->assertArrayHasKey('weighted', $overall);
        $this->assertCount(10, $overall['weighted'], 'Weighted map must have exactly 10 entries (a..j, no k)');
        $this->assertArrayNotHasKey('21.2.k', $overall['weighted']);
    }

    #[Test]
    public function article23TimelineIsPresent(): void
    {
        $payload = $this->service->getDashboardPayload();

        $this->assertArrayHasKey('article23', $payload);
        $this->assertArrayHasKey('letter', $payload['article23']);
        $this->assertSame('23', $payload['article23']['letter']);
    }
}
