<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\Bsi\PanelVerdictApplier;
use App\Service\Bsi\PanelVerdictApplierInterface;
use App\Service\Bsi\PanelVerdictAutoApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PanelVerdictAutoApplier} — the setup-time orchestrator
 * that applies ALL `*_panel_v1.json` verdict fixtures.
 *
 * These tests exercise the orchestration logic (file discovery, framework-code
 * resolution from library/provenance/filename, per-fixture apply() dispatch,
 * graceful skipping) WITHOUT a database — {@see PanelVerdictApplier} and the
 * {@see ComplianceFrameworkRepository} are mocked. The actual verdict→mapping
 * writes are covered by {@see PanelVerdictApplierTest} (kernel-free) and by the
 * per-framework consistency tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class PanelVerdictAutoApplierTest extends TestCase
{
    private PanelVerdictApplierInterface&MockObject $applier;
    private ComplianceFrameworkRepository&MockObject $frameworkRepository;
    private string $tempDir;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->applier             = $this->createMock(PanelVerdictApplierInterface::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);

        $this->tempDir    = sys_get_temp_dir() . '/panel_auto_apply_' . uniqid('', true);
        $this->fixtureDir = $this->tempDir . '/fixtures/library/mappings/panel_verdicts';
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtureDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->fixtureDir);
        @rmdir($this->tempDir . '/fixtures/library/mappings');
        @rmdir($this->tempDir . '/fixtures/library');
        @rmdir($this->tempDir . '/fixtures');
        @rmdir($this->tempDir);
    }

    // ── File discovery ───────────────────────────────────────────────────────

    #[Test]
    public function discoverFixturesFindsOnlyPanelFilesNotCompletenessCandidates(): void
    {
        $this->writeFixture('aaa_to_bbb_panel_v1.json', ['library' => ['source_framework' => 'AAA', 'target_framework' => 'BBB'], 'verdicts' => []]);
        $this->writeFixture('ccc_to_ddd_panel_v1.json', ['library' => ['source_framework' => 'CCC', 'target_framework' => 'DDD'], 'verdicts' => []]);
        // Completeness candidates MUST NOT be discovered.
        $this->writeRaw('aaa_to_bbb_completeness_candidates_v1.json', '{"candidates":[]}');

        $autoApplier = $this->makeAutoApplier();
        $found = $autoApplier->discoverFixtures();

        self::assertCount(2, $found);
        foreach ($found as $path) {
            self::assertStringContainsString('_panel_v1.json', $path);
            self::assertStringNotContainsString('completeness', $path);
        }
    }

    // ── Framework-code resolution ────────────────────────────────────────────

    #[Test]
    public function resolvesCodesFromLibraryBlock(): void
    {
        $path = $this->writeFixture('foo_to_bar_panel_v1.json', [
            'library'  => ['source_framework' => 'ISO27001', 'target_framework' => 'NIS2'],
            'verdicts' => [],
        ]);

        $codes = $this->makeAutoApplier()->resolveFrameworkCodes($path);

        self::assertSame(['ISO27001', 'NIS2'], $codes);
    }

    #[Test]
    public function resolvesCodesFromProvenanceBlockWhenNoLibrary(): void
    {
        // Mirrors dora_to_nis2_panel_v1.json (provenance-only, no library block).
        $path = $this->writeFixture('dora_to_nis2_panel_v1.json', [
            'provenance' => ['source_framework' => 'DORA', 'target_framework' => 'NIS2'],
            'verdicts'   => [],
        ]);

        $codes = $this->makeAutoApplier()->resolveFrameworkCodes($path);

        self::assertSame(['DORA', 'NIS2'], $codes);
    }

    #[Test]
    public function resolvesCodesFromFilenameWhenNeitherLibraryNorProvenanceCarryThem(): void
    {
        // Mirrors the legacy iso27001-2022_to_bsi-grundschutz_panel_v1.json
        // which has a provenance block WITHOUT source/target_framework keys.
        $path = $this->writeFixture('iso27001-2022_to_bsi-grundschutz_panel_v1.json', [
            'provenance' => ['description' => 'legacy fixture without framework codes'],
            'verdicts'   => [],
        ]);

        $codes = $this->makeAutoApplier()->resolveFrameworkCodes($path);

        self::assertSame(['ISO27001', 'BSI_GRUNDSCHUTZ'], $codes);
    }

    // ── Per-fixture dispatch ────────────────────────────────────────────────

    #[Test]
    public function callsApplyOncePerFixtureWithResolvedFrameworks(): void
    {
        $this->writeFixture('one_to_two_panel_v1.json', ['library' => ['source_framework' => 'ISO27001', 'target_framework' => 'NIS2'], 'verdicts' => []]);
        $this->writeFixture('three_to_four_panel_v1.json', ['library' => ['source_framework' => 'DORA', 'target_framework' => 'NIS2'], 'verdicts' => []]);

        $iso  = $this->makeFramework('ISO27001');
        $nis2 = $this->makeFramework('NIS2');
        $dora = $this->makeFramework('DORA');

        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($iso, $nis2, $dora): ?ComplianceFramework {
                return match ($criteria['code'] ?? null) {
                    'ISO27001' => $iso,
                    'NIS2'     => $nis2,
                    'DORA'     => $dora,
                    default    => null,
                };
            }
        );

        // apply() must be called exactly twice (once per fixture).
        $this->applier->expects(self::exactly(2))
            ->method('apply')
            ->willReturn($this->zeroCounts());

        $summary = $this->makeAutoApplier()->applyAll(false);

        self::assertSame(2, $summary['fixtures_total']);
        self::assertSame(2, $summary['applied']);
        self::assertSame(0, $summary['skipped']);
    }

    #[Test]
    public function skipsFixtureGracefullyWhenFrameworkNotLoaded(): void
    {
        $this->writeFixture('missing_to_nis2_panel_v1.json', ['library' => ['source_framework' => 'NOT_LOADED', 'target_framework' => 'NIS2'], 'verdicts' => []]);

        // Repository returns null for everything → framework not loaded.
        $this->frameworkRepository->method('findOneBy')->willReturn(null);

        // apply() must NOT be called when frameworks are missing.
        $this->applier->expects(self::never())->method('apply');

        $summary = $this->makeAutoApplier()->applyAll(false);

        self::assertSame(1, $summary['fixtures_total']);
        self::assertSame(0, $summary['applied']);
        self::assertSame(1, $summary['skipped']);
        self::assertSame('skipped', $summary['per_fixture'][0]['status']);
    }

    #[Test]
    public function aggregatesVerdictCountsAcrossFixtures(): void
    {
        $this->writeFixture('a_to_b_panel_v1.json', ['library' => ['source_framework' => 'ISO27001', 'target_framework' => 'NIS2'], 'verdicts' => []]);

        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            fn(array $c): ComplianceFramework => $this->makeFramework((string) ($c['code'] ?? 'X'))
        );

        $this->applier->method('apply')->willReturn([
            'ki_validiert'             => 5,
            'rejected'                 => 2,
            'needs_review'             => 1,
            'panel_discovered'         => 3,
            'panel_discovered_skipped' => 0,
            'not_matched'              => 0,
            'already_applied'          => 4,
            'total'                    => 11,
        ]);

        $summary = $this->makeAutoApplier()->applyAll(false);

        self::assertSame(5, $summary['ki_validiert']);
        self::assertSame(2, $summary['rejected']);
        self::assertSame(1, $summary['needs_review']);
        self::assertSame(3, $summary['panel_discovered']);
        self::assertSame(4, $summary['already_applied']);
    }

    #[Test]
    public function applyThrowingDoesNotAbortRemainingFixtures(): void
    {
        $this->writeFixture('boom_to_nis2_panel_v1.json', ['library' => ['source_framework' => 'ISO27001', 'target_framework' => 'NIS2'], 'verdicts' => []]);

        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            fn(array $c): ComplianceFramework => $this->makeFramework((string) ($c['code'] ?? 'X'))
        );

        $this->applier->method('apply')->willThrowException(new \RuntimeException('boom'));

        $summary = $this->makeAutoApplier()->applyAll(false);

        // No exception propagates; fixture counted as skipped.
        self::assertSame(1, $summary['fixtures_total']);
        self::assertSame(0, $summary['applied']);
        self::assertSame(1, $summary['skipped']);
    }

    // ── End-to-end proof (real PanelVerdictApplier, mocked repo/EM — no DB) ──

    /**
     * Full-chain proof that running the auto-applier over a real fixture mutates
     * the ComplianceMapping objects so the quality UI reflects the verdicts:
     *   - ki_validiert pair → provenanceSource='panel' + lifecycleState='approved'
     *     → IsoToBsiGapService::trustOf() returns the ki_validiert tier (shown as a
     *       distinct badge on the BSI cross-gap dashboard);
     *   - reject pair → lifecycleState='deprecated' (excluded from coverage queries).
     *
     * Uses the REAL {@see PanelVerdictApplier} wired into the auto-applier, with
     * only the persistence boundary (repository + EntityManager) doubled, so no
     * MySQL test DB is required.
     */
    #[Test]
    public function endToEndAppliesKiValidiertAndRejectToRealMappings(): void
    {
        // Two heuristic mappings the panel adjudicated.
        $iso  = $this->makeFrameworkWithId(1, 'ISO27001');
        $bsi  = $this->makeFrameworkWithId(2, 'BSI_GRUNDSCHUTZ');
        $kiMapping     = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2', $iso, $bsi);
        $rejectMapping = $this->makeHeuristicMapping('A.5.7', 'ORP.4', $iso, $bsi);

        // Real fixture: one ki_validiert, one reject — matched by (iso, baustein).
        $this->writeFixture('iso27001-2022_to_bsi-grundschutz_panel_v1.json', [
            'provenance' => ['source_framework' => 'ISO27001', 'target_framework' => 'BSI_GRUNDSCHUTZ'],
            'verdicts'   => [
                ['iso' => 'A.8.9', 'baustein' => 'SYS.1.2', 'state' => 'ki_validiert', 'mappingPercentage' => 45, 'realVotes' => 4],
                ['iso' => 'A.5.7', 'baustein' => 'ORP.4', 'state' => 'reject', 'realVotes' => 1],
            ],
        ]);

        $mappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $mappingRepository->method('findAllGlobal')->willReturn([$kiMapping, $rejectMapping]);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $realApplier = new PanelVerdictApplier($mappingRepository, $entityManager, $this->tempDir);

        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            fn(array $c): ?ComplianceFramework => match ($c['code'] ?? null) {
                'ISO27001'        => $iso,
                'BSI_GRUNDSCHUTZ' => $bsi,
                default           => null,
            }
        );

        $autoApplier = new PanelVerdictAutoApplier($realApplier, $this->frameworkRepository, $this->tempDir);
        $autoApplier->applyAll(false);

        // ki_validiert pair elevated to panel/approved → trustOf() = ki_validiert.
        self::assertSame('panel', $kiMapping->getProvenanceSource());
        self::assertSame('approved', $kiMapping->getLifecycleState());
        $trustService = new IsoToBsiGapService(
            $this->createMock(\App\Repository\ComplianceRequirementRepository::class),
            $this->createMock(ComplianceMappingRepository::class),
            $this->createMock(\App\Repository\ComplianceRequirementFulfillmentRepository::class),
        );
        self::assertSame(IsoToBsiGapService::TIER_KI_VALIDIERT, $trustService->trustOf($kiMapping));

        // reject pair deprecated → drops out of coverage.
        self::assertSame('deprecated', $rejectMapping->getLifecycleState());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeAutoApplier(): PanelVerdictAutoApplier
    {
        return new PanelVerdictAutoApplier(
            $this->applier,
            $this->frameworkRepository,
            $this->tempDir,
        );
    }

    private function makeFramework(string $code): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $fw->setCode($code);
        $fw->setName($code . ' name');

        return $fw;
    }

    private function makeFrameworkWithId(int $id, string $code): ComplianceFramework&MockObject
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);

        return $fw;
    }

    /**
     * Build a heuristic (no provenance) ISO→BSI mapping that the applier index
     * can match by (sourceRequirementId, baustein-category).
     */
    private function makeHeuristicMapping(
        string $isoControlId,
        string $bausteinCategory,
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
    ): ComplianceMapping {
        $isoReq = $this->createMock(ComplianceRequirement::class);
        $isoReq->method('getRequirementId')->willReturn($isoControlId);
        $isoReq->method('getFramework')->willReturn($iso);

        $bsiReq = $this->createMock(ComplianceRequirement::class);
        $bsiReq->method('getRequirementId')->willReturn($bausteinCategory . '.A1');
        $bsiReq->method('getCategory')->willReturn($bausteinCategory);
        $bsiReq->method('getFramework')->willReturn($bsi);

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($isoReq);
        $mapping->setTargetRequirement($bsiReq);

        return $mapping;
    }

    /** @return array<string,int> */
    private function zeroCounts(): array
    {
        return [
            'ki_validiert'             => 0,
            'rejected'                 => 0,
            'needs_review'             => 0,
            'panel_discovered'         => 0,
            'panel_discovered_skipped' => 0,
            'not_matched'              => 0,
            'already_applied'          => 0,
            'total'                    => 0,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeFixture(string $name, array $data): string
    {
        $path = $this->fixtureDir . '/' . $name;
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function writeRaw(string $name, string $raw): string
    {
        $path = $this->fixtureDir . '/' . $name;
        file_put_contents($path, $raw);

        return $path;
    }
}
