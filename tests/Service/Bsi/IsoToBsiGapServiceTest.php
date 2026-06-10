<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Bsi\BsiGapResult;
use App\Service\Bsi\IsoToBsiGapService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IsoToBsiGapService.
 *
 * All external dependencies (repositories) are mocked. Tests exercise
 * the full state-table defined in the WS-3 specification.
 */
#[AllowMockObjectsWithoutExpectations]
class IsoToBsiGapServiceTest extends TestCase
{
    /** @var MockObject&ComplianceRequirementRepository */
    private MockObject $reqRepo;

    /** @var MockObject&ComplianceMappingRepository */
    private MockObject $mappingRepo;

    /** @var MockObject&ComplianceRequirementFulfillmentRepository */
    private MockObject $fulfillmentRepo;

    private IsoToBsiGapService $service;

    protected function setUp(): void
    {
        $this->reqRepo         = $this->createMock(ComplianceRequirementRepository::class);
        $this->mappingRepo     = $this->createMock(ComplianceMappingRepository::class);
        $this->fulfillmentRepo = $this->createMock(ComplianceRequirementFulfillmentRepository::class);

        $this->service = new IsoToBsiGapService(
            $this->reqRepo,
            $this->mappingRepo,
            $this->fulfillmentRepo,
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeTenant(string $bsiLevel = 'standard'): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getBsiAssuranceLevel')->willReturn($bsiLevel);
        return $tenant;
    }

    private function makeFramework(): ComplianceFramework
    {
        return $this->createMock(ComplianceFramework::class);
    }

    /**
     * Build a stub BSI requirement with a given absicherungsStufe tier.
     */
    private function makeBsiReq(int $id, string $tier, string $requirementId = 'APP.1.1.A1'): ComplianceRequirement
    {
        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getId')->willReturn($id);
        $req->method('getRequirementId')->willReturn($requirementId);
        $req->method('getAbsicherungsStufe')->willReturn($tier);
        $req->method('getCategory')->willReturn('APP.1.1');
        $req->method('getEvidenceDocuments')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        return $req;
    }

    /**
     * Build a stub ISO requirement.
     */
    private function makeIsoReq(string $requirementId = 'A.5.1'): ComplianceRequirement
    {
        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn($requirementId);
        return $req;
    }

    /**
     * Build a stub ComplianceMapping.
     *
     * @param int    $pct            Mapping percentage (0–150)
     * @param string $provenanceSource
     * @param string $lifecycleState
     * @param string $reviewStatus
     */
    private function makeMapping(
        ComplianceRequirement $isoReq,
        ComplianceRequirement $bsiReq,
        int $pct,
        string $provenanceSource = 'algorithm_generated_v1.0',
        string $lifecycleState = 'approved',
        string $reviewStatus = 'unreviewed',
    ): ComplianceMapping {
        $m = $this->createMock(ComplianceMapping::class);
        $m->method('getSourceRequirement')->willReturn($isoReq);
        $m->method('getTargetRequirement')->willReturn($bsiReq);
        $m->method('getMappingPercentage')->willReturn($pct);
        $m->method('getProvenanceSource')->willReturn($provenanceSource);
        $m->method('getLifecycleState')->willReturn($lifecycleState);
        $m->method('getReviewStatus')->willReturn($reviewStatus);
        return $m;
    }

    // ── state-table row 1: fulfilled + 100 % + official → gedeckt / amtlich ─

    #[Test]
    public function fulfilledAndFullCoverageWithOfficialCrosswalkYieldsGedecktAmtlich(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.5.1');
        $bsiReq = $this->makeBsiReq(1, 'standard');

        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'official_bsi_crosswalk');

        $this->reqRepo->method('findByFrameworkAndTiers')
            ->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')
            ->willReturn([$mapping]);
        $this->fulfillmentRepo->method('percentageFor')
            ->willReturn(80);
        $this->fulfillmentRepo->method('evidenceTitlesFor')
            ->willReturn(['ISMS-Policy.pdf']);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $this->assertInstanceOf(BsiGapResult::class, $result);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('gedeckt', $item['state']);
        $this->assertSame('amtlich', $item['trust']);
        $this->assertSame(0, $item['delta']);
        $this->assertSame('A.5.1', $item['isoControl']);
        $this->assertSame(['ISMS-Policy.pdf'], $item['evidence']);
        $this->assertSame(1, $result->bucketCounts['erledigt']);
    }

    // ── state-table row 2: fulfilled + 60 % → partiell, delta 40 ─────────────

    #[Test]
    public function fulfilledWithPartialCoverageYieldsPartiellWithCorrectDelta(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.5.2');
        $bsiReq = $this->makeBsiReq(2, 'standard');

        $mapping = $this->makeMapping($isoReq, $bsiReq, 60, 'manual');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);
        $this->fulfillmentRepo->method('percentageFor')->willReturn(75);
        $this->fulfillmentRepo->method('evidenceTitlesFor')->willReturn([]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('partiell', $item['state']);
        $this->assertSame('bestaetigt', $item['trust']);
        $this->assertSame(40, $item['delta']);
    }

    // ── state-table row 3: mapped but tenant fulfillment 0 → iso_offen ────────

    #[Test]
    public function mappedButZeroTenantFulfillmentYieldsIsoOffen(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.6.1');
        $bsiReq = $this->makeBsiReq(3, 'standard');

        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'official_bsi_crosswalk');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);
        $this->fulfillmentRepo->method('percentageFor')->willReturn(0);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('iso_offen', $item['state']);
        $this->assertSame('amtlich', $item['trust']);
        $this->assertSame(0, $item['delta']);
        $this->assertSame('A.6.1', $item['isoControl']);
        // iso_offen → quick_win bucket
        $this->assertSame(1, $result->bucketCounts['quick_win']);
    }

    // ── state-table row 4: no mapping → ungemappt_unbewertet ─────────────────

    #[Test]
    public function noMappingYieldsUngemapptUnbewertet(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $bsiReq = $this->makeBsiReq(4, 'standard', 'SYS.1.1.A5');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('ungemappt_unbewertet', $item['state']);
        $this->assertSame('-', $item['trust']);
        $this->assertNull($item['isoControl']);
        $this->assertSame([], $item['evidence']);
        // ungemappt_unbewertet → pruefen bucket (default)
        $this->assertSame(1, $result->bucketCounts['pruefen']);
    }

    // ── state-table row 5: fulfilled + 100 % + heuristic → gedeckt BUT pruefen ─

    #[Test]
    public function fulfilledFullCoverageHeuristicTrustStillShowsGedecktButBucketsPruefen(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.7.1');
        $bsiReq = $this->makeBsiReq(5, 'standard');

        // Heuristic trust: unknown provenance source, review status not confirmed
        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'heuristic', 'draft', 'unreviewed');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);
        $this->fulfillmentRepo->method('percentageFor')->willReturn(100);
        $this->fulfillmentRepo->method('evidenceTitlesFor')->willReturn(['Scope-Doc.pdf']);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        // State is still 'gedeckt' — coverage is not hidden
        $this->assertSame('gedeckt', $item['state']);
        $this->assertSame('heuristisch', $item['trust']);
        // But bucket is 'pruefen' because trust is heuristic
        $this->assertSame(0, $result->bucketCounts['erledigt']);
        $this->assertSame(1, $result->bucketCounts['pruefen']);
    }

    // ── state-table row 6a: level 'kern' includes hoch-tier targets ───────────

    #[Test]
    public function kernLevelIncludesHochTierTargets(): void
    {
        $tenant = $this->makeTenant('kern');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        // Verify findByFrameworkAndTiers is called with all three tiers for 'kern'
        $this->reqRepo->expects($this->once())
            ->method('findByFrameworkAndTiers')
            ->with(
                $this->identicalTo($bsi),
                $this->equalTo(['basis', 'standard', 'hoch']),
            )
            ->willReturn([]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $this->assertSame(0, $result->total);
    }

    // ── state-table row 6b: level 'basis' excludes standard-tier targets ──────

    #[Test]
    public function basisLevelExcludesStandardTierTargets(): void
    {
        $tenant = $this->makeTenant('basis');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        // Verify findByFrameworkAndTiers is called with only ['basis'] for 'basis' level
        $this->reqRepo->expects($this->once())
            ->method('findByFrameworkAndTiers')
            ->with(
                $this->identicalTo($bsi),
                $this->equalTo(['basis']),
            )
            ->willReturn([]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $this->assertSame(0, $result->total);
    }

    // ── state-table row 7: bucket aggregation ────────────────────────────────

    #[Test]
    public function bucketAggregationCoversAllFourBuckets(): void
    {
        $tenant = $this->makeTenant('kern');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        // 4 BSI requirements representing each bucket archetype
        $bsiReq1 = $this->makeBsiReq(10, 'basis', 'ORP.1.A1');   // → erledigt
        $bsiReq2 = $this->makeBsiReq(11, 'standard', 'ORP.1.A2'); // → quick_win
        $bsiReq3 = $this->makeBsiReq(12, 'hoch', 'ORP.1.A3');     // → bsi_arbeit (partiell)
        $bsiReq4 = $this->makeBsiReq(13, 'basis', 'ORP.1.A4');    // → pruefen (no mapping)

        $isoReq1 = $this->makeIsoReq('ISO.1');
        $isoReq2 = $this->makeIsoReq('ISO.2');
        $isoReq3 = $this->makeIsoReq('ISO.3');

        // bsiReq1: official mapping, 100%, tenant fulfilled → erledigt
        $m1 = $this->makeMapping($isoReq1, $bsiReq1, 100, 'official_bsi_crosswalk');
        // bsiReq2: official mapping, 100%, tenant NOT fulfilled → quick_win (iso_offen)
        $m2 = $this->makeMapping($isoReq2, $bsiReq2, 100, 'official_bsi_crosswalk');
        // bsiReq3: manual mapping, 60%, tenant fulfilled → bsi_arbeit (partiell)
        $m3 = $this->makeMapping($isoReq3, $bsiReq3, 60, 'manual');
        // bsiReq4: no mapping → pruefen (ungemappt_unbewertet)

        $this->reqRepo->method('findByFrameworkAndTiers')
            ->willReturn([$bsiReq1, $bsiReq2, $bsiReq3, $bsiReq4]);

        $this->mappingRepo->method('findCrossFrameworkMappings')
            ->willReturn([$m1, $m2, $m3]);

        // fulfillmentRepo: bsiReq1's iso source is fulfilled; bsiReq2 not; bsiReq3 fulfilled
        $this->fulfillmentRepo->method('percentageFor')
            ->willReturnMap([
                [$tenant, $isoReq1, 80],  // bsiReq1 — fulfilled
                [$tenant, $isoReq2, 0],   // bsiReq2 — not started
                [$tenant, $isoReq3, 50],  // bsiReq3 — partial
            ]);
        $this->fulfillmentRepo->method('evidenceTitlesFor')
            ->willReturn([]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $this->assertSame(4, $result->total);
        $this->assertSame(1, $result->bucketCounts['erledigt'],   'erledigt bucket');
        $this->assertSame(1, $result->bucketCounts['quick_win'],  'quick_win bucket');
        $this->assertSame(1, $result->bucketCounts['bsi_arbeit'], 'bsi_arbeit bucket');
        $this->assertSame(1, $result->bucketCounts['pruefen'],    'pruefen bucket');
    }

    // ── state-table row 8: fulfillment always via mocked repo (tenant-scoped) ─

    #[Test]
    public function fulfillmentAssertionsGoThroughFulfillmentRepo(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.9.1');
        $bsiReq = $this->makeBsiReq(20, 'standard', 'NET.1.1.A2');

        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'official_bsi_crosswalk');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);

        // The service MUST call percentageFor on the fulfillmentRepo (tenant-scoped)
        $this->fulfillmentRepo->expects($this->once())
            ->method('percentageFor')
            ->with($this->identicalTo($tenant), $this->identicalTo($isoReq))
            ->willReturn(100);

        $this->fulfillmentRepo->expects($this->once())
            ->method('evidenceTitlesFor')
            ->with($this->identicalTo($tenant), $this->identicalTo($isoReq))
            ->willReturn(['Evidence-Doc.pdf']);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('gedeckt', $item['state']);
        $this->assertSame(['Evidence-Doc.pdf'], $item['evidence']);
    }
}
