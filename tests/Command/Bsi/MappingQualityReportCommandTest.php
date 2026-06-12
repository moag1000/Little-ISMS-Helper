<?php

declare(strict_types=1);

namespace App\Tests\Command\Bsi;

use App\Command\Bsi\MappingQualityReportCommand;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Bsi\IsoToBsiGapService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for MappingQualityReportCommand (app:bsi:mapping-quality-report).
 *
 * Covers:
 *  - Missing --source or --target → failure.
 *  - Framework not found → failure.
 *  - Tier distribution output: ki_validiert rows reported; deprecated not in distribution.
 *  - Completeness note shown when panel_discovered mappings exist.
 *  - Heuristic warning shown when heuristic rows remain.
 */
#[AllowMockObjectsWithoutExpectations]
final class MappingQualityReportCommandTest extends TestCase
{
    private ComplianceMappingRepository&MockObject $mappingRepository;
    private ComplianceFrameworkRepository&MockObject $frameworkRepository;
    private IsoToBsiGapService $gapService;

    private ComplianceFramework $nis2;
    private ComplianceFramework $bsi;

    protected function setUp(): void
    {
        $this->mappingRepository  = $this->createMock(ComplianceMappingRepository::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);

        $reqRepo         = $this->createMock(ComplianceRequirementRepository::class);
        $mappingRepo2    = $this->createMock(ComplianceMappingRepository::class);
        $fulfillmentRepo = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->gapService = new IsoToBsiGapService($reqRepo, $mappingRepo2, $fulfillmentRepo);

        $this->nis2 = $this->makeFramework(10, 'NIS2');
        $this->bsi  = $this->makeFramework(2, 'BSI_GRUNDSCHUTZ');
    }

    #[Test]
    public function missingSourceOptionReturnsFailed(): void
    {
        $tester = $this->makeCommandTester();

        $code = $tester->execute(['--target' => 'BSI_GRUNDSCHUTZ']);

        self::assertSame(1, $code);
        self::assertStringContainsString('required', $tester->getDisplay());
    }

    #[Test]
    public function missingTargetOptionReturnsFailed(): void
    {
        $tester = $this->makeCommandTester();

        $code = $tester->execute(['--source' => 'NIS2']);

        self::assertSame(1, $code);
    }

    #[Test]
    public function unknownSourceFrameworkReturnsFailed(): void
    {
        $this->frameworkRepository->method('findOneBy')->willReturn(null);

        $tester = $this->makeCommandTester();
        $code   = $tester->execute(['--source' => 'UNKNOWN', '--target' => 'BSI_GRUNDSCHUTZ']);

        self::assertSame(1, $code);
    }

    #[Test]
    public function reportShowsTierDistributionForKiValidiertMappings(): void
    {
        // One ki_validiert mapping: provenanceSource='panel', reviewStatus='approved', lifecycleState='approved'
        $mapping = $this->makePanelApprovedMapping('21.2.b', 'DER.2.1.A3', 'DER.2.1');

        $this->frameworkRepository
            ->method('findOneBy')
            ->willReturnCallback(fn(array $c) => match ($c['code']) {
                'NIS2'           => $this->nis2,
                'BSI_GRUNDSCHUTZ' => $this->bsi,
                default          => null,
            });

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);

        $tester = $this->makeCommandTester();
        $code   = $tester->execute(['--source' => 'NIS2', '--target' => 'BSI_GRUNDSCHUTZ']);
        $output = $tester->getDisplay();

        self::assertSame(0, $code);
        self::assertStringContainsString('ki_validiert', $output);
        self::assertStringContainsString('NIS2', $output);
    }

    #[Test]
    public function reportShowsHeuristicWarningWhenHeuristicRowsExist(): void
    {
        // An unreviewed heuristic mapping (no provenanceSource, not panel)
        $heuristic = new ComplianceMapping();
        $heuristic->setLifecycleState('approved');

        $srcReq = $this->createMock(ComplianceRequirement::class);
        $srcReq->method('getFramework')->willReturn($this->nis2);
        $tgtReq = $this->createMock(ComplianceRequirement::class);
        $tgtReq->method('getFramework')->willReturn($this->bsi);

        $heuristic->setSourceRequirement($srcReq);
        $heuristic->setTargetRequirement($tgtReq);

        $this->frameworkRepository
            ->method('findOneBy')
            ->willReturnCallback(fn(array $c) => match ($c['code']) {
                'NIS2'           => $this->nis2,
                'BSI_GRUNDSCHUTZ' => $this->bsi,
                default          => null,
            });

        $this->mappingRepository->method('findAllGlobal')->willReturn([$heuristic]);

        $tester = $this->makeCommandTester();
        $code   = $tester->execute(['--source' => 'NIS2', '--target' => 'BSI_GRUNDSCHUTZ']);
        $output = $tester->getDisplay();

        self::assertSame(0, $code);
        self::assertStringContainsString('heuristic', $output);
    }

    #[Test]
    public function reportShowsCompletenessNoteForPanelDiscoveredMappings(): void
    {
        // A panel_discovered mapping: provenanceSource='panel', reviewNotes='panel_discovered'
        $mapping = $this->makePanelApprovedMapping('21.2.a', 'ORP.5.A1', 'ORP.5');
        $mapping->setReviewNotes('panel_discovered');

        $this->frameworkRepository
            ->method('findOneBy')
            ->willReturnCallback(fn(array $c) => match ($c['code']) {
                'NIS2'           => $this->nis2,
                'BSI_GRUNDSCHUTZ' => $this->bsi,
                default          => null,
            });

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);

        $tester = $this->makeCommandTester();
        $code   = $tester->execute(['--source' => 'NIS2', '--target' => 'BSI_GRUNDSCHUTZ']);
        $output = $tester->getDisplay();

        self::assertSame(0, $code);
        self::assertStringContainsString('panel_discovered', $output);
    }

    #[Test]
    public function deprecatedMappingNotCountedAsOperational(): void
    {
        $mapping = $this->makePanelApprovedMapping('21.2.a', 'ISMS.1.4.A1', 'ISMS.1.4');
        $mapping->setLifecycleState('deprecated');

        $this->frameworkRepository
            ->method('findOneBy')
            ->willReturnCallback(fn(array $c) => match ($c['code']) {
                'NIS2'           => $this->nis2,
                'BSI_GRUNDSCHUTZ' => $this->bsi,
                default          => null,
            });

        $this->mappingRepository->method('findAllGlobal')->willReturn([$mapping]);

        $tester = $this->makeCommandTester();
        $code   = $tester->execute(['--source' => 'NIS2', '--target' => 'BSI_GRUNDSCHUTZ']);
        $output = $tester->getDisplay();

        self::assertSame(0, $code);
        self::assertStringContainsString('deprecated', $output);
        // Operational count should be 0 — verify the metric row appears
        self::assertStringContainsString('Operational mappings', $output);
        // The deprecated mapping should not count as operational (0 in the metric row)
        self::assertMatchesRegularExpression('/Operational mappings\s+0\b/', $output);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeCommandTester(): CommandTester
    {
        $command = new MappingQualityReportCommand(
            $this->mappingRepository,
            $this->frameworkRepository,
            $this->gapService,
        );

        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('app:bsi:mapping-quality-report'));
    }

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);
        return $fw;
    }

    private function makePanelApprovedMapping(
        string $sourceReqId,
        string $bsiRequirementId,
        string $bsiCategory,
    ): ComplianceMapping {
        $srcReq = $this->createMock(ComplianceRequirement::class);
        $srcReq->method('getRequirementId')->willReturn($sourceReqId);
        $srcReq->method('getFramework')->willReturn($this->nis2);

        $tgtReq = $this->createMock(ComplianceRequirement::class);
        $tgtReq->method('getRequirementId')->willReturn($bsiRequirementId);
        $tgtReq->method('getCategory')->willReturn($bsiCategory);
        $tgtReq->method('getFramework')->willReturn($this->bsi);

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($srcReq);
        $mapping->setTargetRequirement($tgtReq);
        $mapping->setProvenanceSource('panel');
        $mapping->setLifecycleState('approved');
        $mapping->setReviewStatus('approved');

        return $mapping;
    }
}
