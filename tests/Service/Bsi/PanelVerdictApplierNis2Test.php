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
 * NIS2 Task 4 — PanelVerdictApplier NIS2↔BSI parametrized tests.
 *
 * Covers:
 *  - ki_validiert (NIS2 fixture) → panel/approved + trustOf returns ki_validiert.
 *  - needs_review → reviewStatus='needs_review' + requiresReview=true.
 *  - reject → lifecycleState='deprecated'; not operational.
 *  - panel_discovered → new ComplianceMapping created (provenanceSource='panel', reviewNotes='panel_discovered').
 *  - panel_discovered edge: source requirement not found in DB → skip (no crash).
 *  - panel_discovered edge: target BSI requirement not found in DB → skip (no crash).
 *  - Idempotency for all states.
 *  - Acceptance: ki_validiert dominates (≥ validated set); ≥1 panel_discovered exists; deprecated not in OPERATIONAL_STATES.
 */
#[AllowMockObjectsWithoutExpectations]
final class PanelVerdictApplierNis2Test extends TestCase
{
    private ComplianceMappingRepository&MockObject $mappingRepository;
    private ComplianceRequirementRepository&MockObject $requirementRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private PanelVerdictApplier $applier;
    private IsoToBsiGapService $trustService;

    private ComplianceFramework $nis2;
    private ComplianceFramework $bsi;

    /** Temp directory holding synthesised NIS2 fixtures for tests */
    private string $tempDir;

    private const NIS2_FIXTURE = 'fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json';

    protected function setUp(): void
    {
        $this->mappingRepository     = $this->createMock(ComplianceMappingRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->entityManager         = $this->createMock(EntityManagerInterface::class);

        $this->tempDir = sys_get_temp_dir() . '/panel_verdict_nis2_test_' . uniqid('', true);
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

        $this->nis2 = $this->makeFramework(10, 'NIS2');
        $this->bsi  = $this->makeFramework(2, 'BSI_GRUNDSCHUTZ');
    }

    protected function tearDown(): void
    {
        $dir  = $this->tempDir . '/fixtures/library/mappings/panel_verdicts';
        $file = $dir . '/nis2-art21_to_bsi-grundschutz_panel_v1.json';
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
    public function nis2KiValidiertElevatesMappingToPanelApproved(): void
    {
        $heuristic = $this->makeHeuristicMapping('21.2.b', 'DER.2.1.A3', 'DER.2.1');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 90, 'realVotes' => 4, 'refutationSurvived' => true],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(1, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(0, $counts['needs_review']);
        self::assertSame('panel', $heuristic->getProvenanceSource());
        self::assertSame('approved', $heuristic->getLifecycleState());
        self::assertSame('approved', $heuristic->getReviewStatus());
        self::assertSame(90, $heuristic->getAnalysisConfidence()); // 4 votes → 90
        self::assertSame(90, $heuristic->getMappingPercentage());
    }

    #[Test]
    public function nis2KiValidiertTrustOfReturnsTierKiValidiert(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource('panel');
        $m->setLifecycleState('approved');
        $m->setReviewStatus('approved'); // trustOf checks reviewStatus for 'panel' provenance

        self::assertSame(IsoToBsiGapService::TIER_KI_VALIDIERT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function nis2KiValidiertWithThreeVotesGivesConfidence70(): void
    {
        $heuristic = $this->makeHeuristicMapping('21.2.j', 'CON.1.A2', 'CON.1');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.j', 'baustein' => 'CON.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 25, 'realVotes' => 3],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->method('flush');

        $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(70, $heuristic->getAnalysisConfidence());
    }

    #[Test]
    public function nis2KiValidiertWithTwoVotesGivesDefaultConfidence60(): void
    {
        $heuristic = $this->makeHeuristicMapping('21.2.b', 'DER.2.3.A1', 'DER.2.3');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.3', 'state' => 'ki_validiert',
             'mappingPercentage' => 35, 'realVotes' => 2],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->method('flush');

        $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(60, $heuristic->getAnalysisConfidence());
    }

    // ── needs_review ───────────────────────────────────────────────────────

    #[Test]
    public function nis2NeedsReviewVerdictFlagsForHumanReview(): void
    {
        $heuristic = $this->makeHeuristicMapping('21.2.b', 'DER.2.2.A1', 'DER.2.2');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.2', 'state' => 'needs_review',
             'mappingPercentage' => 35, 'refutationSurvived' => false],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(1, $counts['needs_review']);
        self::assertSame('needs_review', $heuristic->getReviewStatus());
        self::assertTrue($heuristic->isRequiresReview());
    }

    // ── reject ─────────────────────────────────────────────────────────────

    #[Test]
    public function nis2RejectVerdictDeprecatesMappingWithoutDelete(): void
    {
        $heuristic = $this->makeHeuristicMapping('21.2.a', 'ISMS.1.4.A1', 'ISMS.1.4');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.a', 'baustein' => 'ISMS.1.4', 'state' => 'reject',
             'mappingPercentage' => 0, 'realVotes' => 0],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['rejected']);
        self::assertSame('deprecated', $heuristic->getLifecycleState());

        // Must NOT be in operational states
        self::assertFalse($heuristic->isOperational());
    }

    #[Test]
    public function nis2DeprecatedMappingNotInOperationalStates(): void
    {
        $m = new ComplianceMapping();
        $m->setLifecycleState('deprecated');

        self::assertFalse($m->isOperational());
        self::assertNotContains('deprecated', ComplianceMapping::OPERATIONAL_STATES);
    }

    // ── panel_discovered ───────────────────────────────────────────────────

    #[Test]
    public function nis2PanelDiscoveredCreatesNewMapping(): void
    {
        // No existing mapping for this pair
        $this->writeNis2Fixture([
            ['nis2' => '21.2.a', 'baustein' => 'ORP.5', 'state' => 'panel_discovered',
             'mappingPercentage' => 40, 'refutationSurvived' => true, 'relation' => 'partial',
             'rationale' => 'Test rationale'],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([]); // No existing mappings

        // Mock requirement lookup: source NIS2 requirement found
        $nis2Req = $this->makeRequirement('21.2.a', $this->nis2);
        // Mock BSI requirement found by category
        $bsiReq = $this->makeRequirement('ORP.5', $this->bsi);

        $this->requirementRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($nis2Req, $bsiReq): ?ComplianceRequirement {
                if (isset($criteria['requirementId']) && $criteria['requirementId'] === '21.2.a') {
                    return $nis2Req;
                }
                if (isset($criteria['category']) && $criteria['category'] === 'ORP.5') {
                    return $bsiReq;
                }
                return null;
            });

        $persistedMappings = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedMappings): void {
                $persistedMappings[] = $entity;
            });
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(0, $counts['rejected']);
        self::assertSame(0, $counts['needs_review']);
        self::assertSame(1, $counts['panel_discovered']);
        self::assertSame(0, $counts['panel_discovered_skipped']);

        // Verify the new mapping was persisted with correct attributes
        self::assertCount(1, $persistedMappings);
        /** @var ComplianceMapping $newMapping */
        $newMapping = $persistedMappings[0];
        self::assertSame('panel', $newMapping->getProvenanceSource());
        self::assertSame('approved', $newMapping->getLifecycleState());
        self::assertSame('approved', $newMapping->getReviewStatus());
        self::assertSame('panel_discovered', $newMapping->getReviewNotes());
        self::assertSame(40, $newMapping->getMappingPercentage());
        self::assertSame('partial', $newMapping->getMappingType());
    }

    #[Test]
    public function nis2PanelDiscoveredSkipsWhenSourceRequirementNotFound(): void
    {
        $this->writeNis2Fixture([
            ['nis2' => '21.2.x-nonexistent', 'baustein' => 'ORP.5', 'state' => 'panel_discovered',
             'mappingPercentage' => 40, 'relation' => 'partial'],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([]);

        // Source requirement not found
        $this->requirementRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['panel_discovered']);
        self::assertSame(1, $counts['panel_discovered_skipped']);
    }

    #[Test]
    public function nis2PanelDiscoveredSkipsWhenTargetBausteinNotFound(): void
    {
        $this->writeNis2Fixture([
            ['nis2' => '21.2.a', 'baustein' => 'NONEXISTENT.99', 'state' => 'panel_discovered',
             'mappingPercentage' => 40, 'relation' => 'partial'],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([]);

        $nis2Req = $this->makeRequirement('21.2.a', $this->nis2);

        // Source found, target BSI baustein not found
        $this->requirementRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($nis2Req): ?ComplianceRequirement {
                if (isset($criteria['requirementId']) && $criteria['requirementId'] === '21.2.a') {
                    return $nis2Req;
                }
                // category and requirementId lookups for 'NONEXISTENT.99' return null
                return null;
            });

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['panel_discovered']);
        self::assertSame(1, $counts['panel_discovered_skipped']);
    }

    #[Test]
    public function nis2PanelDiscoveredDryRunDoesNotPersistOrFlush(): void
    {
        $this->writeNis2Fixture([
            ['nis2' => '21.2.a', 'baustein' => 'ORP.5', 'state' => 'panel_discovered',
             'mappingPercentage' => 40, 'relation' => 'partial'],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([]);

        $nis2Req = $this->makeRequirement('21.2.a', $this->nis2);
        $bsiReq  = $this->makeRequirement('ORP.5', $this->bsi);

        $this->requirementRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($nis2Req, $bsiReq): ?ComplianceRequirement {
                if (isset($criteria['requirementId']) && $criteria['requirementId'] === '21.2.a') {
                    return $nis2Req;
                }
                if (isset($criteria['category']) && $criteria['category'] === 'ORP.5') {
                    return $bsiReq;
                }
                return null;
            });

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: true);

        self::assertSame(1, $counts['panel_discovered']);
        self::assertSame(0, $counts['panel_discovered_skipped']);
    }

    // ── Idempotency ────────────────────────────────────────────────────────

    #[Test]
    public function nis2IdempotencyKiValidiertAlreadyAppliedIsNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('21.2.b', 'DER.2.1.A3', 'DER.2.1');
        $mapping->setProvenanceSource('panel');
        $mapping->setLifecycleState('approved');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 90, 'realVotes' => 4],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['ki_validiert']);
        self::assertSame(1, $counts['already_applied']);
    }

    #[Test]
    public function nis2IdempotencyRejectAlreadyDeprecatedIsNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('21.2.a', 'ISMS.1.4.A1', 'ISMS.1.4');
        $mapping->setLifecycleState('deprecated');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.a', 'baustein' => 'ISMS.1.4', 'state' => 'reject',
             'mappingPercentage' => 0, 'realVotes' => 0],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['rejected']);
        self::assertSame(1, $counts['already_applied']);
    }

    #[Test]
    public function nis2IdempotencyNeedsReviewAlreadyFlaggedIsNotRewritten(): void
    {
        $mapping = $this->makeHeuristicMapping('21.2.b', 'DER.2.2.A1', 'DER.2.2');
        $mapping->setReviewStatus('needs_review');
        $mapping->setRequiresReview(true);

        $this->writeNis2Fixture([
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.2', 'state' => 'needs_review',
             'mappingPercentage' => 35],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);
        $this->entityManager->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        self::assertSame(0, $counts['needs_review']);
        self::assertSame(1, $counts['already_applied']);
    }

    #[Test]
    public function nis2IdempotencyPanelDiscoveredExistingMappingAlreadyApplied(): void
    {
        // Existing mapping that matches the panel_discovered verdict
        $existingMapping = $this->makeHeuristicMapping('21.2.a', 'ORP.5.A1', 'ORP.5');

        $this->writeNis2Fixture([
            ['nis2' => '21.2.a', 'baustein' => 'ORP.5', 'state' => 'panel_discovered',
             'mappingPercentage' => 40, 'relation' => 'partial'],
        ]);

        $this->mappingRepository->method('findAllGlobal')->willReturn([$existingMapping]);
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $counts = $this->applier->apply(self::NIS2_FIXTURE, $this->nis2, $this->bsi, dryRun: false);

        // Existing mapping found → already_applied (not panel_discovered)
        self::assertSame(0, $counts['panel_discovered']);
        self::assertSame(1, $counts['already_applied']);
    }

    // ── Acceptance: fixture-wide quality checks ────────────────────────────

    #[Test]
    public function acceptanceKiValidiertDominatesInFixture(): void
    {
        // Load the real NIS2 fixture from the project root
        $projectDir  = dirname(__DIR__, 3); // tests/Service/Bsi → project root
        $fixturePath = $projectDir . '/fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json';

        if (!is_file($fixturePath)) {
            self::markTestSkipped('NIS2 panel verdict fixture not found: ' . $fixturePath);
        }

        $raw      = file_get_contents($fixturePath);
        $decoded  = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $verdicts = $decoded['verdicts'] ?? [];

        $kiValidiertCount    = 0;
        $panelDiscoveredCount = 0;

        foreach ($verdicts as $verdict) {
            if ($verdict['state'] === 'ki_validiert') {
                $kiValidiertCount++;
            }
            if ($verdict['state'] === 'panel_discovered') {
                $panelDiscoveredCount++;
            }
        }

        // At least 22 ki_validiert (the validated set from the panel run)
        self::assertGreaterThanOrEqual(22, $kiValidiertCount, 'ki_validiert count should be ≥ 22');

        // At least 1 panel_discovered
        self::assertGreaterThanOrEqual(1, $panelDiscoveredCount, 'At least 1 panel_discovered mapping must exist');
    }

    #[Test]
    public function acceptanceDeprecatedNotInOperationalStates(): void
    {
        // Verify ComplianceMapping::OPERATIONAL_STATES does NOT contain 'deprecated'
        self::assertNotContains('deprecated', ComplianceMapping::OPERATIONAL_STATES);
        self::assertContains('approved', ComplianceMapping::OPERATIONAL_STATES);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Write a minimal NIS2 verdict fixture to the temp project directory.
     *
     * @param list<array<string, mixed>> $verdicts
     */
    private function writeNis2Fixture(array $verdicts): void
    {
        $fixture = [
            'library' => [
                'source_framework' => 'NIS2',
                'target_framework' => 'BSI_GRUNDSCHUTZ',
                'panel' => ['isms-specialist', 'bsi-specialist', 'risk-management-specialist', 'persona-auditor-external'],
                'run_date' => '2026-06-12',
                'amtlich_source' => 'none',
            ],
            'verdicts' => $verdicts,
        ];

        $path = $this->tempDir . '/fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json';
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    }

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);
        return $fw;
    }

    private function makeRequirement(string $requirementId, ComplianceFramework $framework): ComplianceRequirement
    {
        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn($requirementId);
        $req->method('getFramework')->willReturn($framework);
        return $req;
    }

    private function buildMapping(
        string $sourceReqId,
        string $bsiRequirementId,
        string $bsiCategory,
        ?string $provenanceSource,
    ): ComplianceMapping {
        $sourceReq = $this->createMock(ComplianceRequirement::class);
        $sourceReq->method('getRequirementId')->willReturn($sourceReqId);
        $sourceReq->method('getFramework')->willReturn($this->nis2);

        $bsiReq = $this->createMock(ComplianceRequirement::class);
        $bsiReq->method('getRequirementId')->willReturn($bsiRequirementId);
        $bsiReq->method('getCategory')->willReturn($bsiCategory);
        $bsiReq->method('getFramework')->willReturn($this->bsi);

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($sourceReq);
        $mapping->setTargetRequirement($bsiReq);
        if ($provenanceSource !== null) {
            $mapping->setProvenanceSource($provenanceSource);
        }
        return $mapping;
    }

    private function makeHeuristicMapping(
        string $sourceReqId,
        string $bsiRequirementId,
        string $bsiCategory,
    ): ComplianceMapping {
        return $this->buildMapping($sourceReqId, $bsiRequirementId, $bsiCategory, null);
    }
}
