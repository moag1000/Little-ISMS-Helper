<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\Bsi\PanelVerdictApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * P3 Tier-A — DORA↔NIS2 mapping-quality verification tests.
 *
 * ## What this tests
 *
 * 1. FIXTURE INTEGRITY
 *    - dora_to_nis2_panel_v1.json exists and has provenance header.
 *    - dora_to_nis2_completeness_candidates_v1.json exists and has provenance header.
 *    - Exactly 49 ki_validiert, 28 reject, 34 needs_review verdicts.
 *    - Exactly 59 completeness candidates.
 *
 * 2. APPLIER: ki_validiert → panel/approved + trustOf returns ki_validiert.
 *    reject → deprecated; not in OPERATIONAL_STATES.
 *    needs_review → reviewStatus='needs_review' + requiresReview=true.
 *
 * 3. ACCEPTANCE
 *    - 49 ki_validiert are operational after apply.
 *    - 28 reject are deprecated and NOT in OPERATIONAL_STATES.
 *    - Completeness candidates are NOT operational (NOT applied).
 *    - IsoToBsiGapService::TIER_AMTLICH matches official_eu_lex_specialis provenance.
 *
 * 4. detectTargetKey — dora_to_nis2 fixture uses 'target' not 'baustein'.
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraNis2QualityTest extends TestCase
{
    private ComplianceMappingRepository&MockObject $mappingRepository;
    private ComplianceRequirementRepository&MockObject $requirementRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private PanelVerdictApplier $applier;
    private IsoToBsiGapService $trustService;

    private ComplianceFramework $dora;
    private ComplianceFramework $nis2;

    /** Temp directory holding synthesised DORA→NIS2 fixtures for unit tests */
    private string $tempDir;

    /** Real fixture paths — acceptance guards load these */
    private const FIXTURE_PANEL      = __DIR__ . '/../../../fixtures/library/mappings/panel_verdicts/dora_to_nis2_panel_v1.json';
    private const FIXTURE_CANDIDATES = __DIR__ . '/../../../fixtures/library/mappings/panel_verdicts/dora_to_nis2_completeness_candidates_v1.json';

    private const DORA_NIS2_FIXTURE_RELATIVE = 'fixtures/library/mappings/panel_verdicts/dora_to_nis2_panel_v1.json';

    protected function setUp(): void
    {
        $this->mappingRepository     = $this->createMock(ComplianceMappingRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->entityManager         = $this->createMock(EntityManagerInterface::class);

        $this->tempDir = sys_get_temp_dir() . '/dora_nis2_quality_test_' . uniqid('', true);
        mkdir($this->tempDir . '/fixtures/library/mappings/panel_verdicts', 0777, true);

        $this->applier = new PanelVerdictApplier(
            $this->mappingRepository,
            $this->entityManager,
            $this->tempDir,
            $this->requirementRepository,
            new NullLogger(),
        );

        $reqRepo         = $this->createMock(ComplianceRequirementRepository::class);
        $mappingRepo2    = $this->createMock(ComplianceMappingRepository::class);
        $fulfillmentRepo = $this->createMock(\App\Repository\ComplianceRequirementFulfillmentRepository::class);
        $this->trustService = new IsoToBsiGapService($reqRepo, $mappingRepo2, $fulfillmentRepo);

        $this->dora = $this->makeFramework(20, 'DORA');
        $this->nis2 = $this->makeFramework(10, 'NIS2');
    }

    protected function tearDown(): void
    {
        $path = $this->tempDir . '/fixtures/library/mappings/panel_verdicts/dora_to_nis2_panel_v1.json';
        if (is_file($path)) {
            unlink($path);
        }
        @rmdir($this->tempDir . '/fixtures/library/mappings/panel_verdicts');
        @rmdir($this->tempDir . '/fixtures/library/mappings');
        @rmdir($this->tempDir . '/fixtures/library');
        @rmdir($this->tempDir . '/fixtures');
        @rmdir($this->tempDir);
    }

    // ── FIXTURE INTEGRITY ──────────────────────────────────────────────────

    #[Test]
    public function panelFixtureExistsAndIsReadable(): void
    {
        self::assertFileExists(self::FIXTURE_PANEL, 'DORA→NIS2 panel verdict fixture missing');
        self::assertFileIsReadable(self::FIXTURE_PANEL);
    }

    #[Test]
    public function completenessFixtureExistsAndIsReadable(): void
    {
        self::assertFileExists(self::FIXTURE_CANDIDATES, 'DORA→NIS2 completeness candidates fixture missing');
        self::assertFileIsReadable(self::FIXTURE_CANDIDATES);
    }

    #[Test]
    public function panelFixtureHasProvenanceHeader(): void
    {
        $data = json_decode((string) file_get_contents(self::FIXTURE_PANEL), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($data, 'Panel fixture must be valid JSON');
        self::assertArrayHasKey('provenance', $data, 'Panel fixture must have a "provenance" header');
        self::assertArrayHasKey('verdicts', $data, 'Panel fixture must have a "verdicts" array');

        $prov = $data['provenance'];
        self::assertSame('DORA', $prov['source_framework'], 'provenance.source_framework must be DORA');
        self::assertSame('NIS2', $prov['target_framework'], 'provenance.target_framework must be NIS2');
        self::assertSame('relationship amtlich, pairings panel-validated', $prov['note']);
        self::assertContains('isms-specialist', $prov['panel']);
        self::assertContains('risk-management-specialist', $prov['panel']);
    }

    #[Test]
    public function completenessFixtureHasProvenanceHeaderWithUnrefutedNote(): void
    {
        $data = json_decode((string) file_get_contents(self::FIXTURE_CANDIDATES), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertArrayHasKey('provenance', $data);
        self::assertArrayHasKey('candidates', $data);

        $prov = $data['provenance'];
        self::assertStringContainsStringIgnoringCase('UNREFUTED', (string) ($prov['note'] ?? ''));
        self::assertStringContainsStringIgnoringCase('NOT applied', (string) ($prov['note'] ?? ''));
    }

    #[Test]
    public function panelFixtureHasCorrectVerdictCounts(): void
    {
        $data     = json_decode((string) file_get_contents(self::FIXTURE_PANEL), true, 512, JSON_THROW_ON_ERROR);
        $verdicts = $data['verdicts'] ?? [];

        $ki       = array_filter($verdicts, static fn (array $v): bool => $v['state'] === 'ki_validiert');
        $reject   = array_filter($verdicts, static fn (array $v): bool => $v['state'] === 'reject');
        $review   = array_filter($verdicts, static fn (array $v): bool => $v['state'] === 'needs_review');

        self::assertCount(49, $ki,     'Panel must have exactly 49 ki_validiert verdicts');
        self::assertCount(28, $reject, 'Panel must have exactly 28 reject verdicts');
        self::assertCount(34, $review, 'Panel must have exactly 34 needs_review verdicts');
        self::assertCount(111, $verdicts, 'Panel must have exactly 111 total verdicts');
    }

    #[Test]
    public function completenessFixtureHas59Candidates(): void
    {
        $data       = json_decode((string) file_get_contents(self::FIXTURE_CANDIDATES), true, 512, JSON_THROW_ON_ERROR);
        $candidates = $data['candidates'] ?? [];

        self::assertCount(59, $candidates, 'Completeness candidates must have exactly 59 entries');
    }

    #[Test]
    public function panelFixtureSourceFieldsAreDoraOrRtsIds(): void
    {
        $data     = json_decode((string) file_get_contents(self::FIXTURE_PANEL), true, 512, JSON_THROW_ON_ERROR);
        $verdicts = $data['verdicts'] ?? [];

        // Valid DORA source prefixes: DORA-* (main regulation) and RTS-*/ITS-* (delegated acts)
        $validPrefixes = ['DORA-', 'RTS-', 'ITS-'];

        foreach ($verdicts as $i => $verdict) {
            self::assertArrayHasKey('source', $verdict, "verdict[$i] missing 'source' key");
            self::assertArrayHasKey('target', $verdict, "verdict[$i] missing 'target' key");
            self::assertArrayHasKey('state', $verdict, "verdict[$i] missing 'state' key");

            $src = (string) ($verdict['source'] ?? '');
            $ok  = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($src, $prefix)) {
                    $ok = true;
                    break;
                }
            }
            self::assertTrue(
                $ok,
                "verdict[$i] source '$src' must start with DORA-, RTS-, or ITS- (DORA delegated acts)",
            );
        }
    }

    #[Test]
    public function panelFixtureTargetFieldsAreNis2ArticleIds(): void
    {
        $data     = json_decode((string) file_get_contents(self::FIXTURE_PANEL), true, 512, JSON_THROW_ON_ERROR);
        $verdicts = $data['verdicts'] ?? [];
        $validPrefixes = ['Art.20.', 'Art.21.', 'Art.23.'];

        foreach ($verdicts as $i => $verdict) {
            $target = (string) ($verdict['target'] ?? '');
            $ok     = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($target, $prefix)) {
                    $ok = true;
                    break;
                }
            }
            self::assertTrue(
                $ok,
                "verdict[$i] target '$target' must start with Art.20., Art.21., or Art.23.",
            );
        }
    }

    // ── APPLIER: ki_validiert ──────────────────────────────────────────────

    #[Test]
    public function doraKiValidiertElevatesMappingToPanelApproved(): void
    {
        $heuristic = $this->makeHeuristicMapping('DORA-5.1', 'Art.20.1');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-5.1', 'target' => 'Art.20.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 85, 'realVotes' => 4],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(1, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(0, $counts['needs_review']);
        self::assertSame('panel', $heuristic->getProvenanceSource());
        self::assertSame('approved', $heuristic->getLifecycleState());
        self::assertSame('approved', $heuristic->getReviewStatus());
        self::assertSame(90, $heuristic->getAnalysisConfidence()); // 4 votes → 90
        self::assertSame(85, $heuristic->getMappingPercentage());
    }

    #[Test]
    public function doraKiValidiertTrustOfReturnsTierKiValidiert(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource('panel');
        $m->setLifecycleState('approved');
        $m->setReviewStatus('approved');

        self::assertSame(IsoToBsiGapService::TIER_KI_VALIDIERT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function doraKiValidiertWithThreeVotesGivesConfidence70(): void
    {
        $heuristic = $this->makeHeuristicMapping('DORA-6.1', 'Art.21.2.a');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-6.1', 'target' => 'Art.21.2.a', 'state' => 'ki_validiert',
             'mappingPercentage' => 72, 'realVotes' => 3],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->method('flush');

        $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(70, $heuristic->getAnalysisConfidence());
    }

    // ── APPLIER: reject ────────────────────────────────────────────────────

    #[Test]
    public function doraRejectVerdictDeprecatesMappingWithoutDelete(): void
    {
        $heuristic = $this->makeHeuristicMapping('DORA-9.1', 'Art.21.2.h');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-9.1', 'target' => 'Art.21.2.h', 'state' => 'reject',
             'mappingPercentage' => 0, 'realVotes' => 0],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['rejected']);
        self::assertSame('deprecated', $heuristic->getLifecycleState());
        // Mapping must NOT be in OPERATIONAL_STATES after rejection
        self::assertNotContains($heuristic->getLifecycleState(), ComplianceMapping::OPERATIONAL_STATES);
    }

    #[Test]
    public function doraRejectDeprecatedMappingIsNotOperational(): void
    {
        $m = new ComplianceMapping();
        $m->setLifecycleState('deprecated');

        self::assertFalse($m->isOperational(), 'deprecated mapping must not be operational');
        self::assertNotContains('deprecated', ComplianceMapping::OPERATIONAL_STATES);
    }

    // ── APPLIER: needs_review ──────────────────────────────────────────────

    #[Test]
    public function doraNeedsReviewVerdictFlagsForHumanReview(): void
    {
        $heuristic = $this->makeHeuristicMapping('DORA-9.3', 'Art.21.2.h');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-9.3', 'target' => 'Art.21.2.h', 'state' => 'needs_review',
             'mappingPercentage' => 50, 'realVotes' => 2],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(1, $counts['needs_review']);
        self::assertSame('needs_review', $heuristic->getReviewStatus());
        self::assertTrue($heuristic->isRequiresReview());
    }

    // ── APPLIER: dry-run ───────────────────────────────────────────────────

    #[Test]
    public function dryRunDoesNotFlush(): void
    {
        $heuristic = $this->makeHeuristicMapping('DORA-5.1', 'Art.20.1');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-5.1', 'target' => 'Art.20.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 85, 'realVotes' => 4],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::never())->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: true);

        self::assertSame(1, $counts['ki_validiert']);
        // Dry-run: mapping must NOT be changed
        self::assertNull($heuristic->getProvenanceSource());
    }

    // ── APPLIER: not_matched ───────────────────────────────────────────────

    #[Test]
    public function verdictWithNoMatchingMappingCountsAsNotMatched(): void
    {
        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-99.9', 'target' => 'Art.21.2.z', 'state' => 'ki_validiert',
             'mappingPercentage' => 80, 'realVotes' => 4],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['not_matched']);
    }

    // ── APPLIER: idempotency ───────────────────────────────────────────────

    #[Test]
    public function idempotencyKiValidiertAlreadyAppliedNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('DORA-5.1', 'Art.20.1');
        $mapping->setProvenanceSource('panel');
        $mapping->setLifecycleState('approved');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-5.1', 'target' => 'Art.20.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 85, 'realVotes' => 4],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['already_applied']);
    }

    #[Test]
    public function idempotencyRejectAlreadyDeprecatedNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('DORA-9.1', 'Art.21.2.h');
        $mapping->setLifecycleState('deprecated');

        $this->writeDoraNis2Fixture([
            ['source' => 'DORA-9.1', 'target' => 'Art.21.2.h', 'state' => 'reject',
             'mappingPercentage' => 0, 'realVotes' => 0],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(self::DORA_NIS2_FIXTURE_RELATIVE, $this->dora, $this->nis2, dryRun: false);

        self::assertSame(0, $counts['rejected']);
        self::assertSame(1, $counts['already_applied']);
    }

    // ── ACCEPTANCE: DORA→NIS2 lex-specialis amtlich tier ──────────────────

    #[Test]
    public function officialEuLexSpecialisTrustOfReturnsAmtlich(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_OFFICIAL_EU_LEX_SPECIALIS);

        self::assertSame(
            IsoToBsiGapService::TIER_AMTLICH,
            $this->trustService->trustOf($m),
            'official_eu_lex_specialis provenance must resolve to TIER_AMTLICH',
        );
    }

    #[Test]
    public function officialEuLexSpecialisConstantMatchesYamlValue(): void
    {
        self::assertSame(
            'official_eu_lex_specialis',
            IsoToBsiGapService::PROVENANCE_OFFICIAL_EU_LEX_SPECIALIS,
        );
    }

    // ── ACCEPTANCE: panel fixture verdicts after apply ─────────────────────

    #[Test]
    public function fixtureHas49KiValidiertVerdicts(): void
    {
        $data     = json_decode((string) file_get_contents(self::FIXTURE_PANEL), true, 512, JSON_THROW_ON_ERROR);
        $verdicts = $data['verdicts'] ?? [];
        $ki       = array_filter($verdicts, static fn (array $v): bool => $v['state'] === 'ki_validiert');

        self::assertCount(49, $ki, '49 panel-validated pairings must be ki_validiert');
    }

    #[Test]
    public function fixtureHas28RejectVerdicts(): void
    {
        $data     = json_decode((string) file_get_contents(self::FIXTURE_PANEL), true, 512, JSON_THROW_ON_ERROR);
        $verdicts = $data['verdicts'] ?? [];
        $reject   = array_filter($verdicts, static fn (array $v): bool => $v['state'] === 'reject');

        self::assertCount(28, $reject, '28 weak pairings must be rejected');
    }

    #[Test]
    public function deprecatedMappingsAreNotInOperationalStates(): void
    {
        // Guard: ComplianceMapping::OPERATIONAL_STATES must NOT contain 'deprecated'
        self::assertNotContains('deprecated', ComplianceMapping::OPERATIONAL_STATES);
        self::assertContains('approved', ComplianceMapping::OPERATIONAL_STATES);
    }

    #[Test]
    public function completenessCandiatesAreNotAppliedAsOperationalMappings(): void
    {
        // The completeness fixture is UNREFUTED proposals only.
        // It must NOT contain any 'ki_validiert' state entries — those would be treated as operational.
        $data       = json_decode((string) file_get_contents(self::FIXTURE_CANDIDATES), true, 512, JSON_THROW_ON_ERROR);
        $candidates = $data['candidates'] ?? [];

        foreach ($candidates as $i => $candidate) {
            // Candidates have neither 'state' nor 'ki_validiert'/'reject' — they are proposals only
            self::assertArrayNotHasKey(
                'state',
                $candidate,
                "completeness candidate[$i] must not have a 'state' field (would be applied as verdict)",
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Write a minimal DORA→NIS2 verdict fixture to the temp project directory.
     *
     * @param list<array<string, mixed>> $verdicts
     */
    private function writeDoraNis2Fixture(array $verdicts): void
    {
        $fixture = [
            'provenance' => [
                'source_framework' => 'DORA',
                'target_framework' => 'NIS2',
                'panel' => ['isms-specialist', 'risk-management-specialist', 'persona-consultant-senior', 'persona-auditor-external'],
                'run_date' => '2026-06-12',
                'note' => 'relationship amtlich, pairings panel-validated',
            ],
            'verdicts' => $verdicts,
        ];

        $path = $this->tempDir . '/fixtures/library/mappings/panel_verdicts/dora_to_nis2_panel_v1.json';
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    }

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);
        return $fw;
    }

    /**
     * Build a heuristic (unreviewed) DORA→NIS2 mapping.
     * Source = DORA requirement (e.g. 'DORA-5.1').
     * Target = NIS2 requirement (e.g. 'Art.20.1').
     */
    private function makeHeuristicMapping(string $doraReqId, string $nis2ReqId): ComplianceMapping
    {
        $doraReq = $this->createMock(ComplianceRequirement::class);
        $doraReq->method('getRequirementId')->willReturn($doraReqId);
        $doraReq->method('getCategory')->willReturn(null);
        $doraReq->method('getFramework')->willReturn($this->dora);

        $nis2Req = $this->createMock(ComplianceRequirement::class);
        $nis2Req->method('getRequirementId')->willReturn($nis2ReqId);
        $nis2Req->method('getCategory')->willReturn(null);
        $nis2Req->method('getFramework')->willReturn($this->nis2);

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($doraReq);
        $mapping->setTargetRequirement($nis2Req);

        return $mapping;
    }
}
