<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\ExcelExportService;
use App\Service\Tisax\EnxScheduleExporter;
use App\Service\Tisax\TisaxMaturityAssessmentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EnxScheduleExporter.
 *
 * buildSpreadsheet() is tested via mock EM + ExcelExportService.
 * exportAsResponse() streams output — @coverage-skip for the streaming path
 * (covered in Playwright screenshot persona).
 *
 * @coverage-skip exportAsResponse() — streams XLSX to php://output; use integration test for HTTP path
 */
#[AllowMockObjectsWithoutExpectations]
final class EnxScheduleExporterTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $excelService;
    private EnxScheduleExporter $exporter;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->excelService  = $this->createMock(ExcelExportService::class);

        // TisaxMaturityAssessmentService is final — construct with mocked EM.
        // EnxScheduleExporter only accesses the static LEVEL_MAP constant from
        // this service; no instance methods are called in buildSpreadsheet().
        $maturityService = new TisaxMaturityAssessmentService($this->entityManager);

        $this->exporter = new EnxScheduleExporter(
            $this->entityManager,
            $this->excelService,
            $maturityService,
        );
    }

    #[Test]
    public function build_spreadsheet_creates_three_sheets(): void
    {
        $spreadsheet = new Spreadsheet();
        $this->excelService->method('createSpreadsheet')->willReturn($spreadsheet);
        $this->excelService->method('addHeaderRow');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $framework = $this->createMock(ComplianceFramework::class);
        $tenant    = $this->createMock(Tenant::class);
        $tenant->method('getName')->willReturn('Acme Corp');

        $result = $this->exporter->buildSpreadsheet($framework, $tenant);

        self::assertInstanceOf(Spreadsheet::class, $result);
        self::assertSame(3, $result->getSheetCount());
    }

    #[Test]
    public function build_spreadsheet_names_sheets_correctly(): void
    {
        $spreadsheet = new Spreadsheet();
        $this->excelService->method('createSpreadsheet')->willReturn($spreadsheet);
        $this->excelService->method('addHeaderRow');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $framework = $this->createMock(ComplianceFramework::class);
        $tenant    = $this->createMock(Tenant::class);
        $tenant->method('getName')->willReturn('Acme Corp');

        $result = $this->exporter->buildSpreadsheet($framework, $tenant);

        $sheetTitles = array_map(fn(Worksheet $s) => $s->getTitle(), $result->getAllSheets());

        self::assertContains('Information Security (IS)',   $sheetTitles);
        self::assertContains('Prototype Protection (Proto)', $sheetTitles);
        self::assertContains('Data Protection (DataPro)',   $sheetTitles);
    }

    #[Test]
    public function build_spreadsheet_populates_rows_for_requirement_with_maturity(): void
    {
        $spreadsheet = new Spreadsheet();
        $this->excelService->method('createSpreadsheet')->willReturn($spreadsheet);
        $this->excelService->method('addHeaderRow');

        // Create one requirement with maturity data
        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getCategory')->willReturn('information_security');
        $req->method('getRequirementId')->willReturn('1.1.1');
        $req->method('getTitle')->willReturn('Test Requirement');
        $req->method('getDataSourceMapping')->willReturn(['iso27001' => 'A.5.1']);
        $req->method('getMaturityCurrent')->willReturn('managed');   // level 2
        $req->method('getMaturityTarget')->willReturn('established'); // level 3

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$req]);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $framework = $this->createMock(ComplianceFramework::class);
        $tenant    = $this->createMock(Tenant::class);
        $tenant->method('getName')->willReturn('Acme Corp');

        $result = $this->exporter->buildSpreadsheet($framework, $tenant);

        // IS sheet is index 0; verify cell A2 contains requirement ID
        $isSheet = $result->getSheet(0);
        self::assertSame('1.1.1', $isSheet->getCell('A2')->getValue());
    }
}
