<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit\Generator;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Service\Audit\Generator\RiskRegisterWorkbookGenerator;
use App\Service\Audit\Generator\WorkbookStyleHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class RiskRegisterWorkbookGeneratorTest extends TestCase
{
    #[Test]
    public function itSupportsOnlyRiskRegisterType(): void
    {
        $generator = new RiskRegisterWorkbookGenerator($this->createStub(RiskRepository::class));

        self::assertTrue($generator->supportsExportType('risk-register'));
        self::assertFalse($generator->supportsExportType('soa'));
        self::assertFalse($generator->supportsExportType('control-implementation'));
    }

    #[Test]
    public function itReturnsNonEmptySpreadsheet(): void
    {
        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $result = $generator->generate($this->buildTenantStub());

        self::assertInstanceOf(Spreadsheet::class, $result);
    }

    #[Test]
    public function itProducesTwoSheets(): void
    {
        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        self::assertCount(2, $spreadsheet->getAllSheets());
    }

    #[Test]
    public function itHasExpectedSheetNames(): void
    {
        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $names = array_map(fn($s): string => $s->getTitle(), $spreadsheet->getAllSheets());

        self::assertContains('Cover', $names);
        self::assertContains('Risks', $names);
    }

    #[Test]
    public function risksSheetContainsOneDataRowPerRisk(): void
    {
        $risk = $this->buildRiskStub(1, 'Ransomware attack', 4, 5, 2, 3);

        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([$risk]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Risks');
        self::assertNotNull($sheet);
        self::assertSame(1, $sheet->getCell('A2')->getValue());
        self::assertSame('Ransomware attack', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function inherentScoreIsProductOfProbabilityAndImpact(): void
    {
        $risk = $this->buildRiskStub(1, 'Test Risk', 4, 5, 1, 1);
        // inherent = 4 * 5 = 20

        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([$risk]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Risks');
        self::assertNotNull($sheet);
        self::assertSame(20, $sheet->getCell('F2')->getValue()); // inherent score column
    }

    #[Test]
    public function residualScoreIsColorCodedRed(): void
    {
        // residual = 4 * 5 = 20 → red
        $risk = $this->buildRiskStub(1, 'High Risk', 5, 5, 4, 5);

        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([$risk]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Risks');
        self::assertNotNull($sheet);

        // I2 = residual score cell; verify fill colour is set (non-null)
        $fill = $sheet->getStyle('I2')->getFill();
        self::assertNotNull($fill->getStartColor()->getARGB());
        // Red threshold colour should end with risk-red hex
        self::assertStringEndsWith(WorkbookStyleHelper::RISK_RED, $fill->getStartColor()->getARGB());
    }

    #[Test]
    public function residualScoreIsColorCodedGreen(): void
    {
        // residual = 1 * 2 = 2 → green
        $risk = $this->buildRiskStub(1, 'Low Risk', 5, 5, 1, 2);

        $repo = $this->createStub(RiskRepository::class);
        $repo->method('findBy')->willReturn([$risk]);

        $generator = new RiskRegisterWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Risks');
        self::assertNotNull($sheet);

        $fill = $sheet->getStyle('I2')->getFill();
        self::assertStringEndsWith(WorkbookStyleHelper::RISK_GREEN, $fill->getStartColor()->getARGB());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function buildTenantStub(): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getName')->willReturn('Risk Test AG');
        return $t;
    }

    private function buildRiskStub(
        int $id,
        string $title,
        int $probability,
        int $impact,
        int $residualProbability,
        int $residualImpact,
    ): Risk {
        $risk = $this->createStub(Risk::class);
        $risk->method('getId')->willReturn($id);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getCategory')->willReturn('security');
        $risk->method('getProbability')->willReturn($probability);
        $risk->method('getImpact')->willReturn($impact);
        $risk->method('getInherentRiskLevel')->willReturn($probability * $impact);
        $risk->method('getResidualProbability')->willReturn($residualProbability);
        $risk->method('getResidualImpact')->willReturn($residualImpact);
        $risk->method('getResidualRiskLevel')->willReturn($residualProbability * $residualImpact);
        $risk->method('getTreatmentStrategy')->willReturn(TreatmentStrategy::Mitigate);
        $risk->method('getTreatmentDescription')->willReturn(null);
        $risk->method('getEffectiveRiskOwner')->willReturn(null);
        $risk->method('getStatus')->willReturn(RiskStatus::Identified);
        $risk->method('isFormallyAccepted')->willReturn(false);
        $risk->method('isRequiresDPIA')->willReturn(false);
        $risk->method('getReviewDate')->willReturn(null);
        $risk->method('isInvolvesPersonalData')->willReturn(false);
        return $risk;
    }
}
