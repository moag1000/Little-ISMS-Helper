<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\Bsi\PanelVerdictApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * WS-5b Stage 2 — PanelVerdictApplier unit tests.
 *
 * Covers:
 *  - ki_validiert verdict → mapping gets provenanceSource='panel', lifecycleState='approved',
 *    analysisConfidence derived from realVotes, mappingPercentage set;
 *    trustOf() returns ki_validiert.
 *  - reject verdict → mapping gets lifecycleState='deprecated'; no longer operational.
 *  - needs_review verdict → mapping gets reviewStatus='needs_review' + requiresReview=true.
 *  - Idempotency: re-running does not double-count or re-write.
 *  - dry-run: counts computed but no mutations / no flush.
 *  - Official CRT rows are skipped (not panel candidates).
 *  - CRT-corroborated rows (stage 1) are skipped.
 *  - not_matched: unrecognised (baustein, iso) pair → counted as not_matched.
 */
#[AllowMockObjectsWithoutExpectations]
final class PanelVerdictApplierTest extends TestCase
{
    private ComplianceMappingRepository&MockObject $mappingRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private PanelVerdictApplier $applier;
    private IsoToBsiGapService $trustService;

    private ComplianceFramework $iso;
    private ComplianceFramework $bsi;

    /** Temp directory holding a synthesised fixture for tests that exercise loadVerdicts() */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->mappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->entityManager     = $this->createMock(EntityManagerInterface::class);

        $this->tempDir = sys_get_temp_dir() . '/panel_verdict_test_' . uniqid('', true);
        mkdir($this->tempDir . '/fixtures/library/mappings/panel_verdicts', 0777, true);

        $this->applier = new PanelVerdictApplier(
            $this->mappingRepository,
            $this->entityManager,
            $this->tempDir,
        );

        $reqRepo         = $this->createMock(\App\Repository\ComplianceRequirementRepository::class);
        $mappingRepo2    = $this->createMock(ComplianceMappingRepository::class);
        $fulfillmentRepo = $this->createMock(\App\Repository\ComplianceRequirementFulfillmentRepository::class);
        $this->trustService = new IsoToBsiGapService($reqRepo, $mappingRepo2, $fulfillmentRepo);

        $this->iso = $this->makeFramework(1, 'ISO27001');
        $this->bsi = $this->makeFramework(2, 'BSI_GRUNDSCHUTZ');
    }

    protected function tearDown(): void
    {
        // Clean up temp fixture files
        $dir = $this->tempDir . '/fixtures/library/mappings/panel_verdicts';
        $file = $dir . '/iso27001-2022_to_bsi-grundschutz_panel_v1.json';
        if (is_file($file)) {
            unlink($file);
        }
        @rmdir($dir);
        @rmdir($this->tempDir . '/fixtures/library/mappings');
        @rmdir($this->tempDir . '/fixtures/library');
        @rmdir($this->tempDir . '/fixtures');
        @rmdir($this->tempDir);
    }

    // ── ki_validiert ───────────────────────────────────────────────────────

    #[Test]
    public function kiValidiertVerdictElevatesMappingToPanelApproved(): void
    {
        $heuristic = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');

        $this->writeFixture([
            ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 45, 'realVotes' => 4, 'relations' => ['partial', 'partial', 'partial', 'partial']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(1, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(0, $counts['needs_review']);
        self::assertSame('panel', $heuristic->getProvenanceSource());
        self::assertSame('approved', $heuristic->getLifecycleState());
        self::assertSame(90, $heuristic->getAnalysisConfidence()); // 4 votes → 90
        self::assertSame(45, $heuristic->getMappingPercentage());
    }

    #[Test]
    public function kiValidiertWithThreeVotesGivesConfidence70(): void
    {
        $heuristic = $this->makeHeuristicMapping('A.8.10', 'OPS.1.2.2.A2', 'OPS.1.2.2');

        $this->writeFixture([
            ['iso' => 'A.8.10', 'baustein' => 'OPS.1.2.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 25, 'realVotes' => 3, 'relations' => ['related', 'related', 'related', 'related']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->method('flush');

        $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(70, $heuristic->getAnalysisConfidence());
    }

    #[Test]
    public function kiValidiertTrustOfReturnsTierKiValidiert(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource('panel');
        $m->setLifecycleState('approved');
        $m->setReviewStatus('approved'); // trustOf checks reviewStatus for 'panel' provenance

        self::assertSame(IsoToBsiGapService::TIER_KI_VALIDIERT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function panelApprovedMappingIsNotInPruefenBucket(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource('panel');
        $m->setReviewStatus('approved');

        self::assertFalse($this->trustService->requiresReview($m));
    }

    // ── reject ─────────────────────────────────────────────────────────────

    #[Test]
    public function rejectVerdictDeprecatesMappingWithoutDelete(): void
    {
        $heuristic = $this->makeHeuristicMapping('A.8.23', 'APP.3.2.A3', 'APP.3.2');

        $this->writeFixture([
            ['iso' => 'A.8.23', 'baustein' => 'APP.3.2', 'state' => 'reject',
             'mappingPercentage' => 25, 'realVotes' => 1, 'relations' => ['related', 'partial', 'none', 'none']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['rejected']);
        self::assertSame('deprecated', $heuristic->getLifecycleState());

        // Must NOT be in operational states
        self::assertFalse($heuristic->isOperational());
    }

    // ── needs_review ───────────────────────────────────────────────────────

    #[Test]
    public function needsReviewVerdictFlagsForHumanReview(): void
    {
        $heuristic = $this->makeHeuristicMapping('A.8.4', 'APP.4.2.A1', 'APP.4.2');

        $this->writeFixture([
            ['iso' => 'A.8.4', 'baustein' => 'APP.4.2', 'state' => 'needs_review',
             'mappingPercentage' => 15, 'realVotes' => 2, 'relations' => ['related', 'none', 'related', 'related']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(1, $counts['needs_review']);
        self::assertSame('needs_review', $heuristic->getReviewStatus());
        self::assertTrue($heuristic->isRequiresReview());
    }

    // ── Idempotency ────────────────────────────────────────────────────────

    #[Test]
    public function idempotency_kiValidiertAlreadyAppliedIsNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');
        // Simulate already applied
        $mapping->setProvenanceSource('panel');
        $mapping->setLifecycleState('approved');

        $this->writeFixture([
            ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 45, 'realVotes' => 4, 'relations' => ['partial', 'partial', 'partial', 'partial']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['already_applied']);
    }

    #[Test]
    public function idempotency_rejectAlreadyDeprecatedIsNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('A.8.23', 'APP.3.2.A3', 'APP.3.2');
        $mapping->setLifecycleState('deprecated');

        $this->writeFixture([
            ['iso' => 'A.8.23', 'baustein' => 'APP.3.2', 'state' => 'reject',
             'mappingPercentage' => 25, 'realVotes' => 1, 'relations' => ['related', 'none', 'none', 'none']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['rejected']);
        self::assertSame(1, $counts['already_applied']);
    }

    #[Test]
    public function idempotency_needsReviewAlreadyFlaggedIsNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('A.8.4', 'APP.4.2.A1', 'APP.4.2');
        $mapping->setReviewStatus('needs_review');
        $mapping->setRequiresReview(true);

        $this->writeFixture([
            ['iso' => 'A.8.4', 'baustein' => 'APP.4.2', 'state' => 'needs_review',
             'mappingPercentage' => 15, 'realVotes' => 2, 'relations' => ['related', 'none', 'related', 'related']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['needs_review']);
        self::assertSame(1, $counts['already_applied']);
    }

    // ── Dry-run ────────────────────────────────────────────────────────────

    #[Test]
    public function dryRunDoesNotFlushOrMutate(): void
    {
        $heuristic = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');

        $this->writeFixture([
            ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 45, 'realVotes' => 4, 'relations' => ['partial', 'partial', 'partial', 'partial']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::never())->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: true);

        self::assertSame(1, $counts['ki_validiert']);
        // Mapping must NOT have been mutated
        self::assertNull($heuristic->getProvenanceSource());
        self::assertSame('draft', $heuristic->getLifecycleState());
    }

    // ── Skipped rows ───────────────────────────────────────────────────────

    #[Test]
    public function officialCrtRowsAreSkippedAsNotCandidates(): void
    {
        // Official CRT row — should NOT be a panel candidate
        $crtMapping = $this->buildMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server', IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);

        $this->writeFixture([
            ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 45, 'realVotes' => 4, 'relations' => ['partial', 'partial', 'partial', 'partial']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$crtMapping]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        // The verdict has no candidate (CRT row was skipped) → not_matched
        self::assertSame(1, $counts['not_matched']);
        self::assertSame(0, $counts['ki_validiert']);
        // Official CRT provenanceSource must remain unchanged
        self::assertSame(IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT, $crtMapping->getProvenanceSource());
    }

    #[Test]
    public function crtCorroboratedRowsAreSkippedAsAlreadyElevated(): void
    {
        // Stage-1 corroborated row — should NOT be a panel candidate
        $corroborated = $this->buildMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server', IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);

        $this->writeFixture([
            ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 45, 'realVotes' => 4, 'relations' => ['partial', 'partial', 'partial', 'partial']],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$corroborated]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(1, $counts['not_matched']);
        self::assertSame(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED, $corroborated->getProvenanceSource());
    }

    #[Test]
    public function verdictWithNoMatchingMappingIsCountedAsNotMatched(): void
    {
        $this->writeFixture([
            ['iso' => 'A.5.99', 'baustein' => 'NONEXISTENT.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 40, 'realVotes' => 4, 'relations' => []],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(PanelVerdictApplier::FIXTURE_PATH, $this->iso, $this->bsi, dryRun: false);

        self::assertSame(1, $counts['not_matched']);
        self::assertSame(0, $counts['ki_validiert']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Write a minimal verdict fixture to the temp project directory.
     *
     * @param list<array<string, mixed>> $verdicts
     */
    private function writeFixture(array $verdicts): void
    {
        $fixture = [
            'provenance' => [
                'description' => 'Test fixture',
                'panel' => ['bsi-specialist', 'isms-specialist', 'consultant-senior', 'isb-practitioner'],
                'run_date' => '2026-06-11',
            ],
            'verdicts' => $verdicts,
        ];

        $path = $this->tempDir . '/fixtures/library/mappings/panel_verdicts/iso27001-2022_to_bsi-grundschutz_panel_v1.json';
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    }

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);
        return $fw;
    }

    private function buildMapping(
        string $isoControlId,
        string $bsiRequirementId,
        string $bsiCategory,
        ?string $provenanceSource,
    ): ComplianceMapping {
        $isoReq = $this->createMock(ComplianceRequirement::class);
        $isoReq->method('getRequirementId')->willReturn($isoControlId);
        $isoReq->method('getFramework')->willReturn($this->iso);

        $bsiReq = $this->createMock(ComplianceRequirement::class);
        $bsiReq->method('getRequirementId')->willReturn($bsiRequirementId);
        $bsiReq->method('getCategory')->willReturn($bsiCategory);
        $bsiReq->method('getFramework')->willReturn($this->bsi);

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($isoReq);
        $mapping->setTargetRequirement($bsiReq);
        if ($provenanceSource !== null) {
            $mapping->setProvenanceSource($provenanceSource);
        }
        return $mapping;
    }

    private function makeHeuristicMapping(
        string $isoControlId,
        string $bsiRequirementId,
        string $bsiCategory,
    ): ComplianceMapping {
        return $this->buildMapping($isoControlId, $bsiRequirementId, $bsiCategory, null);
    }
}
