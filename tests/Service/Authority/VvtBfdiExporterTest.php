<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Repository\ProcessingActivityRepository;
use App\Service\Authority\VvtBfdiExporter;
use App\Service\PdfExportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class VvtBfdiExporterTest extends TestCase
{
    private MockObject $processingActivityRepository;
    private MockObject $pdfExportService;
    private VvtBfdiExporter $exporter;

    protected function setUp(): void
    {
        $this->processingActivityRepository = $this->createMock(ProcessingActivityRepository::class);
        $this->pdfExportService             = $this->createMock(PdfExportService::class);

        $this->exporter = new VvtBfdiExporter(
            $this->processingActivityRepository,
            $this->pdfExportService,
        );
    }

    // ─── Fixture helpers ──────────────────────────────────────────────────────

    private function buildTenant(string $name = 'Test AG'): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($name);
        $tenant->setCode('test-ag');
        $tenant->setDpoContactName('Dr. Datenschutz');
        $tenant->setDpoContactEmail('dpo@test.example.com');

        return $tenant;
    }

    /**
     * Build a minimal ProcessingActivity with required fields populated.
     */
    private function buildActivity(string $activityName = 'Kundenverwaltung'): ProcessingActivity
    {
        $tenant = $this->buildTenant();

        $activity = new ProcessingActivity();
        // Use reflection to bypass private setters where needed, or use setters
        $activity->setName($activityName);
        $activity->setPurposes(['Vertragserfüllung', 'Kundendienst']);
        $activity->setDataSubjectCategories(['customers', 'employees']);
        $activity->setPersonalDataCategories(['identification', 'contact', 'financial']);
        $activity->setLegalBasis('contract');
        $activity->setRetentionPeriod('10 Jahre nach Vertragsende (§257 HGB)');
        $activity->setHasThirdCountryTransfer(false);
        $activity->setTenant($tenant);

        return $activity;
    }

    // ─── exportXlsx ───────────────────────────────────────────────────────────

    #[Test]
    public function exportXlsxReturnsSpreadsheetWithExpectedSheets(): void
    {
        $tenant    = $this->buildTenant();
        $activity1 = $this->buildActivity('Kundenverwaltung');
        $activity2 = $this->buildActivity('Lieferantenverwaltung');
        $activity3 = $this->buildActivity('Personalabrechnung');

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$activity1, $activity2, $activity3]);

        $spreadsheet = $this->exporter->exportXlsx($tenant);

        // Should have 5 tabs: Cover, VVT-Hauptliste, Sub-Processors, TOMs-Mapping, Auditor-Notes
        self::assertCount(5, $spreadsheet->getAllSheets());

        $sheetTitles = array_map(
            fn ($s) => $s->getTitle(),
            $spreadsheet->getAllSheets(),
        );

        self::assertContains('Cover', $sheetTitles);
        self::assertContains('VVT-Hauptliste', $sheetTitles);
        self::assertContains('Sub-Processors', $sheetTitles);
        self::assertContains('TOMs-Mapping', $sheetTitles);
        self::assertContains('Auditor-Notes', $sheetTitles);
    }

    #[Test]
    public function exportXlsxMainSheetHasCorrectHeaders(): void
    {
        $tenant = $this->buildTenant();

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$this->buildActivity()]);

        $spreadsheet = $this->exporter->exportXlsx($tenant);

        // Find the VVT-Hauptliste sheet
        $mainSheet = $spreadsheet->getSheetByName('VVT-Hauptliste');
        self::assertNotNull($mainSheet);

        // Verify first header column
        $firstHeader = $mainSheet->getCell('A1')->getValue();
        self::assertSame('Verantwortlicher', $firstHeader);
    }

    #[Test]
    public function exportXlsxCoverContainsTenantInfo(): void
    {
        $tenant = $this->buildTenant('My Test AG');

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$this->buildActivity()]);

        $spreadsheet = $this->exporter->exportXlsx($tenant);

        $coverSheet = $spreadsheet->getSheetByName('Cover');
        self::assertNotNull($coverSheet);

        // B4 should contain the tenant name
        self::assertSame('My Test AG', $coverSheet->getCell('B4')->getValue());
    }

    #[Test]
    public function exportXlsxMainSheetHasOneRowPerActivity(): void
    {
        $tenant = $this->buildTenant();

        $activities = [
            $this->buildActivity('Activity 1'),
            $this->buildActivity('Activity 2'),
            $this->buildActivity('Activity 3'),
        ];

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn($activities);

        $spreadsheet = $this->exporter->exportXlsx($tenant);

        $mainSheet = $spreadsheet->getSheetByName('VVT-Hauptliste');
        self::assertNotNull($mainSheet);

        // Row 1 = headers, rows 2-4 = data
        self::assertSame(4, $mainSheet->getHighestRow());
    }

    // ─── exportCsv ────────────────────────────────────────────────────────────

    #[Test]
    public function exportCsvReturnsStringWithBom(): void
    {
        $tenant = $this->buildTenant();

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$this->buildActivity()]);

        $csv = $this->exporter->exportCsv($tenant);

        // UTF-8 BOM
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    #[Test]
    public function exportCsvContainsBfdiColumnHeaders(): void
    {
        $tenant = $this->buildTenant();

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$this->buildActivity()]);

        $csv = $this->exporter->exportCsv($tenant);

        self::assertStringContainsString('Verantwortlicher', $csv);
        self::assertStringContainsString('Datenschutzbeauftragter', $csv);
        self::assertStringContainsString('Verarbeitungszweck', $csv);
        self::assertStringContainsString('Löschfristen', $csv);
    }

    #[Test]
    public function exportCsvContainsActivityData(): void
    {
        $tenant   = $this->buildTenant('Test Org GmbH');
        $activity = $this->buildActivity('Kundenbestellung');

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$activity]);

        $csv = $this->exporter->exportCsv($tenant);

        // Verantwortlicher column contains tenant name
        self::assertStringContainsString('Test Org GmbH', $csv);
        // Purposes data should appear in the CSV
        self::assertStringContainsString('Vertragserfüllung', $csv);
        // Retention period should be in the CSV
        self::assertStringContainsString('10 Jahre', $csv);
    }

    // ─── exportPdf ────────────────────────────────────────────────────────────

    #[Test]
    public function exportPdfDelegatesToPdfExportService(): void
    {
        $tenant = $this->buildTenant();

        $this->processingActivityRepository
            ->method('findByTenant')
            ->willReturn([$this->buildActivity()]);

        $this->pdfExportService
            ->expects(self::once())
            ->method('generatePdf')
            ->with('processing_activity/vvt_bfdi_export.html.twig', self::callback(
                fn ($data) => isset($data['tenant']) && isset($data['activities']) && isset($data['exportedAt']),
            ))
            ->willReturn('%PDF-1.4 ...');

        $result = $this->exporter->exportPdf($tenant);

        self::assertStringStartsWith('%PDF', $result);
    }
}
