<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\Bsi\MappingCorroborationService;
use App\Service\Bsi\PanelVerdictApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P2 Regression — both ISO↔BSI and NIS2↔BSI pairs run through the generalized
 * MappingCorroborationService and PanelVerdictApplier and produce identical
 * results to before the generalization.
 *
 * These tests assert:
 *  1. MappingCorroborationService::corroborate() produces the same corroboration
 *     counts for both pairs (matching by Baustein for BSI target).
 *  2. PanelVerdictApplier::apply() produces the same verdict counts for both pairs.
 *  3. The isFrameworkPairMapping() / isIsoBsiMapping() BC alias both return the
 *     same result.
 */
#[AllowMockObjectsWithoutExpectations]
final class MappingPipelineRegressionTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tempDir = sys_get_temp_dir() . '/pipeline_regression_' . uniqid('', true);
        mkdir($this->tempDir . '/fixtures/library/mappings/panel_verdicts', 0777, true);
    }

    protected function tearDown(): void
    {
        $dir = $this->tempDir . '/fixtures/library/mappings/panel_verdicts';
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($dir);
        @rmdir($this->tempDir . '/fixtures/library/mappings');
        @rmdir($this->tempDir . '/fixtures/library');
        @rmdir($this->tempDir . '/fixtures');
        @rmdir($this->tempDir);
    }

    // ── MappingCorroborationService — ISO↔BSI pair ────────────────────────

    #[Test]
    public function isoBsiCorroborationMatchesExpectedCounts(): void
    {
        [$iso, $bsi] = $this->makePair(1, 'ISO27001', 2, 'BSI_GRUNDSCHUTZ');

        // Official CRT: SYS.1.2 ↔ A.8.9 (Baustein level)
        $crt         = $this->makeBsiMapping($iso, $bsi, 'A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server', IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);
        // Heuristic corroborated: same Baustein + ISO control
        $heuristic1  = $this->makeBsiMapping($iso, $bsi, 'A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server', null);
        // Heuristic residual: different ISO control → not corroborated
        $heuristic2  = $this->makeBsiMapping($iso, $bsi, 'A.8.10', 'SYS.1.2.A6', 'SYS.1.2 Windows Server', null);

        $service = $this->makeCorroborationService([$crt, $heuristic1, $heuristic2]);

        $result = $service->corroborate($iso, $bsi, IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT, dryRun: true);

        self::assertSame(1, $result['corroborated'],    'ISO↔BSI: 1 heuristic corroborated by CRT');
        self::assertSame(1, $result['residual'],        'ISO↔BSI: 1 heuristic residual');
        self::assertSame(1, $result['already_official'], 'ISO↔BSI: 1 official CRT row');

        // BC alias: isIsoBsiMapping() must behave identically to isFrameworkPairMapping()
        self::assertTrue(
            $service->isIsoBsiMapping($heuristic1, $iso, $bsi),
            'BC alias isIsoBsiMapping() must still work',
        );
        self::assertFalse(
            $service->isIsoBsiMapping($heuristic1, $bsi, $iso),
            'BC alias must respect direction (src/tgt swap → false)',
        );
    }

    #[Test]
    public function isoBsiCorroborationElevatesProvenanceInNonDryRun(): void
    {
        [$iso, $bsi] = $this->makePair(1, 'ISO27001', 2, 'BSI_GRUNDSCHUTZ');

        $crt       = $this->makeBsiMapping($iso, $bsi, 'A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server', IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);
        $heuristic = $this->makeBsiMapping($iso, $bsi, 'A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server', null);

        $this->entityManager->expects(self::once())->method('flush');
        $service = $this->makeCorroborationService([$crt, $heuristic]);

        $service->corroborate($iso, $bsi, IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT, dryRun: false);

        self::assertSame(
            IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED,
            $heuristic->getProvenanceSource(),
            'Heuristic must be elevated to crt_corroborated',
        );
    }

    // ── MappingCorroborationService — NIS2↔BSI pair ───────────────────────

    #[Test]
    public function nis2BsiCorroborationMatchesExpectedCounts(): void
    {
        [$nis2, $bsi] = $this->makePair(10, 'NIS2', 2, 'BSI_GRUNDSCHUTZ');

        // For NIS2↔BSI, use the same official-CRT sentinel as for ISO↔BSI
        $crt        = $this->makeBsiMapping($nis2, $bsi, '21.2.b', 'DER.2.1.A1', 'DER.2.1', IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);
        $heuristic1 = $this->makeBsiMapping($nis2, $bsi, '21.2.b', 'DER.2.1.A5', 'DER.2.1', null);
        $heuristic2 = $this->makeBsiMapping($nis2, $bsi, '21.2.c', 'DER.2.1.A6', 'DER.2.1', null);

        $service = $this->makeCorroborationService([$crt, $heuristic1, $heuristic2]);

        $result = $service->corroborate($nis2, $bsi, IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT, dryRun: true);

        self::assertSame(1, $result['corroborated'],    'NIS2↔BSI: 1 heuristic corroborated by CRT');
        self::assertSame(1, $result['residual'],        'NIS2↔BSI: 1 heuristic residual');
        self::assertSame(1, $result['already_official'], 'NIS2↔BSI: 1 official CRT row');
    }

    #[Test]
    public function nis2BsiCorroborationElevatesProvenance(): void
    {
        [$nis2, $bsi] = $this->makePair(10, 'NIS2', 2, 'BSI_GRUNDSCHUTZ');

        $crt       = $this->makeBsiMapping($nis2, $bsi, '21.2.b', 'DER.2.1.A1', 'DER.2.1', IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);
        $heuristic = $this->makeBsiMapping($nis2, $bsi, '21.2.b', 'DER.2.1.A5', 'DER.2.1', null);

        $this->entityManager->expects(self::once())->method('flush');
        $service = $this->makeCorroborationService([$crt, $heuristic]);

        $service->corroborate($nis2, $bsi, IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT, dryRun: false);

        self::assertSame(
            IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED,
            $heuristic->getProvenanceSource(),
            'NIS2 heuristic must be elevated to crt_corroborated',
        );
    }

    // ── MappingCorroborationService — cross-pair isolation ─────────────────

    #[Test]
    public function corroborationIgnoresMappingsFromOtherFrameworkPairs(): void
    {
        [$iso, $bsi] = $this->makePair(1, 'ISO27001', 2, 'BSI_GRUNDSCHUTZ');
        [$nis2]      = $this->makePair(10, 'NIS2', 2, 'BSI_GRUNDSCHUTZ');

        $isoCrt  = $this->makeBsiMapping($iso,  $bsi, 'A.8.9', 'SYS.1.2.A3', 'SYS.1.2', IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);
        $nis2Map = $this->makeBsiMapping($nis2, $bsi, 'A.8.9', 'SYS.1.2.A5', 'SYS.1.2', null);

        $service = $this->makeCorroborationService([$isoCrt, $nis2Map]);

        // Running for ISO pair — NIS2 mapping must be invisible
        $result = $service->corroborate($iso, $bsi, IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT, dryRun: true);

        self::assertSame(0, $result['corroborated'], 'NIS2 mapping must not be corroborated against ISO CRT');
        self::assertSame(0, $result['residual'],     'NIS2 mapping must not appear in ISO pair residual');
        self::assertSame(1, $result['already_official'], 'CRT row still counted');
    }

    // ── PanelVerdictApplier — ISO↔BSI pair ───────────────────────────────

    #[Test]
    public function isoBsiPanelVerdictApplierProducesCorrectCounts(): void
    {
        [$iso, $bsi] = $this->makePair(1, 'ISO27001', 2, 'BSI_GRUNDSCHUTZ');

        $heuristic = $this->makeBsiMapping($iso, $bsi, 'A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server', null);

        $mappingRepo = $this->createMock(ComplianceMappingRepository::class);
        $mappingRepo->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->method('flush');

        $fixturePath = PanelVerdictApplier::FIXTURE_PATH;
        $this->writeFixture($fixturePath, [
            ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert',
             'mappingPercentage' => 45, 'realVotes' => 4, 'relations' => ['partial', 'partial', 'partial', 'partial']],
        ]);

        $applier = new PanelVerdictApplier($mappingRepo, $this->entityManager, $this->tempDir);

        $counts = $applier->apply($fixturePath, $iso, $bsi, dryRun: true);

        self::assertSame(1, $counts['ki_validiert'], 'ISO↔BSI: ki_validiert verdict matched');
        self::assertSame(0, $counts['not_matched'],  'ISO↔BSI: no unmatched verdicts');
        self::assertSame(1, $counts['total'],        'ISO↔BSI: total = 1');
        // Dry-run: mapping must NOT be mutated
        self::assertNull($heuristic->getProvenanceSource());
    }

    // ── PanelVerdictApplier — NIS2↔BSI pair ──────────────────────────────

    #[Test]
    public function nis2BsiPanelVerdictApplierProducesCorrectCounts(): void
    {
        [$nis2, $bsi] = $this->makePair(10, 'NIS2', 2, 'BSI_GRUNDSCHUTZ');

        $heuristic = $this->makeBsiMapping($nis2, $bsi, '21.2.b', 'DER.2.1.A3', 'DER.2.1', null);

        $mappingRepo = $this->createMock(ComplianceMappingRepository::class);
        $mappingRepo->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->method('flush');

        $fixturePath = PanelVerdictApplier::FIXTURE_PATH_NIS2;
        $this->writeFixture($fixturePath, [
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 90, 'realVotes' => 4, 'refutationSurvived' => true],
        ]);

        $applier = new PanelVerdictApplier($mappingRepo, $this->entityManager, $this->tempDir);

        $counts = $applier->apply($fixturePath, $nis2, $bsi, dryRun: true);

        self::assertSame(1, $counts['ki_validiert'], 'NIS2↔BSI: ki_validiert verdict matched');
        self::assertSame(0, $counts['not_matched'],  'NIS2↔BSI: no unmatched verdicts');
        self::assertSame(1, $counts['total'],        'NIS2↔BSI: total = 1');
        // Dry-run: mapping must NOT be mutated
        self::assertNull($heuristic->getProvenanceSource());
    }

    #[Test]
    public function nis2BsiPanelVerdictApplierMutatesOnNonDryRun(): void
    {
        [$nis2, $bsi] = $this->makePair(10, 'NIS2', 2, 'BSI_GRUNDSCHUTZ');

        $heuristic = $this->makeBsiMapping($nis2, $bsi, '21.2.b', 'DER.2.1.A3', 'DER.2.1', null);

        $mappingRepo = $this->createMock(ComplianceMappingRepository::class);
        $mappingRepo->method('findAllGlobal')->willReturn([$heuristic]);
        $this->entityManager->expects(self::once())->method('flush');

        $fixturePath = PanelVerdictApplier::FIXTURE_PATH_NIS2;
        $this->writeFixture($fixturePath, [
            ['nis2' => '21.2.b', 'baustein' => 'DER.2.1', 'state' => 'ki_validiert',
             'mappingPercentage' => 90, 'realVotes' => 4],
        ]);

        $applier = new PanelVerdictApplier($mappingRepo, $this->entityManager, $this->tempDir);

        $applier->apply($fixturePath, $nis2, $bsi, dryRun: false);

        self::assertSame('panel',    $heuristic->getProvenanceSource());
        self::assertSame('approved', $heuristic->getLifecycleState());
        self::assertSame(90,         $heuristic->getAnalysisConfidence());
    }

    // ── targetKey() strategy ──────────────────────────────────────────────

    #[Test]
    public function targetKeyUsesBausteinForBsiTarget(): void
    {
        [, $bsi] = $this->makePair(1, 'ISO27001', 2, 'BSI_GRUNDSCHUTZ');

        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn('SYS.1.2.A5');
        $req->method('getCategory')->willReturn('SYS.1.2 Windows Server');

        $service = new MappingCorroborationService(
            $this->createMock(ComplianceMappingRepository::class),
            $this->entityManager,
        );

        self::assertSame('SYS.1.2', $service->targetKey($bsi, $req));
    }

    #[Test]
    public function targetKeyUsesRawRequirementIdForNonBsiTarget(): void
    {
        [$iso] = $this->makePair(1, 'ISO27001', 2, 'BSI_GRUNDSCHUTZ');

        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn('A.8.9');
        $req->method('getCategory')->willReturn('Access Control');

        $service = new MappingCorroborationService(
            $this->createMock(ComplianceMappingRepository::class),
            $this->entityManager,
        );

        // ISO is not BSI_GRUNDSCHUTZ → raw requirementId
        self::assertSame('A.8.9', $service->targetKey($iso, $req));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{ComplianceFramework, ComplianceFramework}
     */
    private function makePair(int $idA, string $codeA, int $idB, string $codeB): array
    {
        return [$this->makeFramework($idA, $codeA), $this->makeFramework($idB, $codeB)];
    }

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);
        return $fw;
    }

    private function makeBsiMapping(
        ComplianceFramework $source,
        ComplianceFramework $target,
        string $srcReqId,
        string $tgtReqId,
        string $tgtCategory,
        ?string $provenance,
    ): ComplianceMapping {
        $srcReq = $this->createMock(ComplianceRequirement::class);
        $srcReq->method('getRequirementId')->willReturn($srcReqId);
        $srcReq->method('getFramework')->willReturn($source);

        $tgtReq = $this->createMock(ComplianceRequirement::class);
        $tgtReq->method('getRequirementId')->willReturn($tgtReqId);
        $tgtReq->method('getCategory')->willReturn($tgtCategory);
        $tgtReq->method('getFramework')->willReturn($target);

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($srcReq);
        $mapping->setTargetRequirement($tgtReq);
        if ($provenance !== null) {
            $mapping->setProvenanceSource($provenance);
        }
        return $mapping;
    }

    private function makeCorroborationService(array $mappings): MappingCorroborationService
    {
        $repo = $this->createMock(ComplianceMappingRepository::class);
        $repo->method('findAllGlobal')->willReturn($mappings);
        return new MappingCorroborationService($repo, $this->entityManager);
    }

    /**
     * Write a verdict fixture to the temp project directory, deriving the directory from
     * the relative path.
     *
     * @param list<array<string, mixed>> $verdicts
     */
    private function writeFixture(string $relativePath, array $verdicts): void
    {
        $fixture = [
            'provenance' => [
                'description' => 'Regression test fixture',
                'run_date'    => '2026-06-12',
            ],
            'verdicts' => $verdicts,
        ];

        $fullPath = $this->tempDir . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, json_encode($fixture, JSON_THROW_ON_ERROR));
    }
}
