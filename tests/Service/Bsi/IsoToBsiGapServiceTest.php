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
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 80, 'evidence' => ['ISMS-Policy.pdf']]);

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
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 75, 'evidence' => []]);

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
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 0, 'evidence' => []]);

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
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 100, 'evidence' => ['Scope-Doc.pdf']]);

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

        // fulfillmentDataFor: bsiReq1's iso source is fulfilled; bsiReq2 not; bsiReq3 fulfilled
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturnMap([
                [$tenant, $isoReq1, ['pct' => 80, 'evidence' => []]],  // bsiReq1 — fulfilled
                [$tenant, $isoReq2, ['pct' => 0,  'evidence' => []]],  // bsiReq2 — not started
                [$tenant, $isoReq3, ['pct' => 50, 'evidence' => []]],  // bsiReq3 — partial
            ]);

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

        // The service MUST call fulfillmentDataFor once — combining pct + evidence
        // in a single tenant-scoped repository call (Q-2: no double round-trip).
        $this->fulfillmentRepo->expects($this->once())
            ->method('fulfillmentDataFor')
            ->with($this->identicalTo($tenant), $this->identicalTo($isoReq))
            ->willReturn(['pct' => 100, 'evidence' => ['Evidence-Doc.pdf']]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('gedeckt', $item['state']);
        $this->assertSame(['Evidence-Doc.pdf'], $item['evidence']);
    }

    // ── Q-6: two mappings from different ISO sources → highest % wins ─────────

    #[Test]
    public function whenTwoMappingsFromDifferentIsoSourcesHighestPercentageWinsAndTrustDerived(): void
    {
        // BSI requirement ORP.2.A5 is mapped from two different ISO controls:
        //   • ISO A.8.1 at 40 % (manual → bestaetigt trust)
        //   • ISO A.6.3 at 90 % (official_bsi_crosswalk → amtlich trust)  ← winning
        // Expected: state uses A.6.3 at 90 % (partiell, delta 10), trust = amtlich.

        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReqLow  = $this->makeIsoReq('A.8.1');  // 40 % mapping
        $isoReqHigh = $this->makeIsoReq('A.6.3');  // 90 % mapping (winner)
        $bsiReq     = $this->makeBsiReq(30, 'standard', 'ORP.2.A5');

        $mappingLow  = $this->makeMapping($isoReqLow,  $bsiReq, 40, 'manual');
        $mappingHigh = $this->makeMapping($isoReqHigh, $bsiReq, 90, 'official_bsi_crosswalk');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        // Intentionally provide low-% mapping first to verify sorting works
        $this->mappingRepo->method('findCrossFrameworkMappings')
            ->willReturn([$mappingLow, $mappingHigh]);

        // Only fulfillmentDataFor for the WINNING source (A.6.3 / isoReqHigh) must be called
        $this->fulfillmentRepo->expects($this->once())
            ->method('fulfillmentDataFor')
            ->with($this->identicalTo($tenant), $this->identicalTo($isoReqHigh))
            ->willReturn(['pct' => 75, 'evidence' => ['AuditReport.pdf']]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        // Highest-% mapping (90) drives the classification
        $this->assertSame('partiell', $item['state'],    'partiell: 90 % < 100 %');
        $this->assertSame('amtlich',  $item['trust'],    'trust from the winning mapping (official_bsi_crosswalk)');
        $this->assertSame(10,         $item['delta'],    'delta = 100 - 90');
        $this->assertSame('A.6.3',    $item['isoControl'], 'winning ISO control');
        $this->assertSame(['AuditReport.pdf'], $item['evidence']);
        // partiell → bsi_arbeit bucket
        $this->assertSame(1, $result->bucketCounts['bsi_arbeit']);
    }

    // ── Q-1 cross-tenant evidence isolation ──────────────────────────────────

    #[Test]
    public function tenantBDoesNotSeeTenantAEvidenceTitlesForSameSharedRequirement(): void
    {
        // Both tenants share the same ComplianceRequirement (framework-level data)
        // and the same mapping. Tenant A has evidence; Tenant B has none.
        // The service must pass the correct tenant object to fulfillmentDataFor so
        // the repository's DQL filters doc.tenant = :tenant — preventing the leak.

        $tenantA = $this->makeTenant('standard');
        $tenantB = $this->makeTenant('standard');
        $iso     = $this->makeFramework();
        $bsi     = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.5.1');
        $bsiReq = $this->makeBsiReq(40, 'standard', 'APP.2.1.A3');

        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'official_bsi_crosswalk');

        // Both tenants see the same BSI requirement and the same mapping
        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);

        // Tenant A: has a fulfillment record with evidence documents
        $this->fulfillmentRepo
            ->method('fulfillmentDataFor')
            ->willReturnCallback(
                static function (Tenant $t, ComplianceRequirement $r) use ($tenantA, $tenantB): array {
                    if ($t === $tenantA) {
                        // Tenant A has evidence (e.g. uploaded ISMS-Policy.pdf)
                        return ['pct' => 100, 'evidence' => ['ISMS-Policy.pdf', 'RiskRegister.pdf']];
                    }
                    if ($t === $tenantB) {
                        // Tenant B has a fulfillment record but NO evidence linked to their tenant
                        return ['pct' => 80, 'evidence' => []];
                    }
                    return ['pct' => 0, 'evidence' => []];
                }
            );

        // Build gap for tenant A — should show evidence
        $resultA = $this->service->buildGap($tenantA, $iso, $bsi);
        $itemA   = $resultA->items[0];
        $this->assertSame('gedeckt', $itemA['state']);
        $this->assertSame(['ISMS-Policy.pdf', 'RiskRegister.pdf'], $itemA['evidence'],
            'Tenant A sees their own evidence documents');

        // Build gap for tenant B — must NOT see tenant A's evidence
        $resultB = $this->service->buildGap($tenantB, $iso, $bsi);
        $itemB   = $resultB->items[0];
        $this->assertSame('gedeckt', $itemB['state']);
        $this->assertSame([], $itemB['evidence'],
            'Tenant B must NOT see tenant A\'s evidence titles for the same shared requirement');
    }

    // ── WS-5b: amtlich_gestuetzt trust tier ──────────────────────────────────

    /**
     * A mapping with provenanceSource = 'crt_corroborated' MUST resolve to
     * the 'amtlich_gestuetzt' trust tier.
     */
    #[Test]
    public function crtCorroboratedMappingYieldsAmtlichGestueztTrust(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.5.1');
        $bsiReq = $this->makeBsiReq(50, 'standard', 'SYS.1.2.A3');

        // crt_corroborated provenance → amtlich_gestuetzt tier
        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'crt_corroborated');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 80, 'evidence' => ['SomeDoc.pdf']]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('gedeckt', $item['state']);
        $this->assertSame('amtlich_gestuetzt', $item['trust'],
            'crt_corroborated provenance must yield amtlich_gestuetzt trust tier');
    }

    /**
     * A mapping with provenanceSource = 'crt_corroborated' MUST land in the
     * 'erledigt' bucket (not in 'pruefen') because amtlich_gestuetzt is a
     * trusted tier.
     */
    #[Test]
    public function crtCorroboratedFulfilledMappingLandsInErledigtNotPruefen(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        $isoReq = $this->makeIsoReq('A.8.9');
        $bsiReq = $this->makeBsiReq(51, 'standard', 'SYS.1.2.A5');

        $mapping = $this->makeMapping($isoReq, $bsiReq, 100, 'crt_corroborated');

        $this->reqRepo->method('findByFrameworkAndTiers')->willReturn([$bsiReq]);
        $this->mappingRepo->method('findCrossFrameworkMappings')->willReturn([$mapping]);
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 100, 'evidence' => []]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $item = $result->items[0];
        $this->assertSame('gedeckt', $item['state']);
        $this->assertSame('amtlich_gestuetzt', $item['trust']);
        $this->assertSame(1, $result->bucketCounts['erledigt'],
            'amtlich_gestuetzt trust must land in erledigt bucket, not pruefen');
        $this->assertSame(0, $result->bucketCounts['pruefen'],
            'pruefen bucket must be 0 — amtlich_gestuetzt is trusted');
    }

    /**
     * Verify that only heuristisch trust forces pruefen — amtlich_gestuetzt does NOT.
     * This is the core invariant of the WS-5b tier extension.
     */
    #[Test]
    public function onlyHeuristischForcesPreufen(): void
    {
        $tenant = $this->makeTenant('standard');
        $iso    = $this->makeFramework();
        $bsi    = $this->makeFramework();

        // bsiReq1: crt_corroborated + fulfilled → erledigt (NOT pruefen)
        $bsiReq1 = $this->makeBsiReq(60, 'standard', 'NET.1.1.A1');
        $isoReq1 = $this->makeIsoReq('A.8.20');
        $m1      = $this->makeMapping($isoReq1, $bsiReq1, 100, 'crt_corroborated');

        // bsiReq2: heuristic + fulfilled → pruefen (heuristic overrides state)
        $bsiReq2 = $this->makeBsiReq(61, 'standard', 'NET.1.1.A2');
        $isoReq2 = $this->makeIsoReq('A.8.21');
        $m2      = $this->makeMapping($isoReq2, $bsiReq2, 100, 'heuristic', 'draft', 'unreviewed');

        $this->reqRepo->method('findByFrameworkAndTiers')
            ->willReturn([$bsiReq1, $bsiReq2]);
        $this->mappingRepo->method('findCrossFrameworkMappings')
            ->willReturn([$m1, $m2]);
        $this->fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturn(['pct' => 100, 'evidence' => []]);

        $result = $this->service->buildGap($tenant, $iso, $bsi);

        $this->assertSame(1, $result->bucketCounts['erledigt'],
            'crt_corroborated (amtlich_gestuetzt) must be erledigt');
        $this->assertSame(1, $result->bucketCounts['pruefen'],
            'heuristisch trust must still be pruefen');
    }
}
