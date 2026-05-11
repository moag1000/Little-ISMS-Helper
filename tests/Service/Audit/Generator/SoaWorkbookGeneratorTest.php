<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit\Generator;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\Audit\Generator\SoaWorkbookGenerator;
use App\Service\Audit\Generator\WorkbookStyleHelper;
use Doctrine\Common\Collections\ArrayCollection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SoaWorkbookGeneratorTest extends TestCase
{
    #[Test]
    public function itSupportsOnlySoaExportType(): void
    {
        $generator = new SoaWorkbookGenerator($this->createStub(ControlRepository::class));

        self::assertTrue($generator->supportsExportType('soa'));
        self::assertFalse($generator->supportsExportType('risk-register'));
        self::assertFalse($generator->supportsExportType('control-implementation'));
        self::assertFalse($generator->supportsExportType('compliance-fulfillment'));
    }

    #[Test]
    public function itReturnsASpreadsheet(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new SoaWorkbookGenerator($repo);
        $tenant = $this->buildTenantStub();

        $result = $generator->generate($tenant);

        self::assertInstanceOf(Spreadsheet::class, $result);
    }

    #[Test]
    public function itProducesFiveSheets(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new SoaWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        self::assertCount(5, $spreadsheet->getAllSheets());
    }

    #[Test]
    public function itHasExpectedSheetNames(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new SoaWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheetNames = array_map(
            fn($s): string => $s->getTitle(),
            $spreadsheet->getAllSheets()
        );

        self::assertContains('Cover', $sheetNames);
        self::assertContains('Controls', $sheetNames);
        self::assertContains('Implementation-Status', $sheetNames);
        self::assertContains('Evidence-Links', $sheetNames);
        self::assertContains('Auditor-Notes', $sheetNames);
    }

    #[Test]
    public function coverSheetContainsProvenance(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new SoaWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $cover = $spreadsheet->getSheetByName('Cover');
        self::assertNotNull($cover);

        // The generator version stamp must appear on the Cover sheet
        $found = false;
        foreach ($cover->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (str_contains((string) $cell->getValue(), WorkbookStyleHelper::GENERATOR_VERSION)) {
                    $found = true;
                    break 2;
                }
            }
        }
        self::assertTrue($found, 'Generator version stamp not found on Cover sheet');
    }

    #[Test]
    public function controlsSheetHasHeaderRowWithExpectedColumns(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new SoaWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Controls');
        self::assertNotNull($sheet);

        $headerRow = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $val = $cell->getValue();
                if ($val !== null && $val !== '') {
                    $headerRow[] = $val;
                }
            }
        }

        self::assertContains('Control ID', $headerRow);
        self::assertContains('Control Title', $headerRow);
        self::assertContains('Applicable (Yes/No)', $headerRow);
    }

    #[Test]
    public function controlsSheetContainsOneDataRowPerControl(): void
    {
        $control = $this->buildControlStub('5.1', 'Policies for information security');

        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([$control]);

        $generator = new SoaWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Controls');
        self::assertNotNull($sheet);

        // Row 1 = header, row 2 = first control data row
        self::assertSame('5.1', $sheet->getCell('A2')->getValue());
        self::assertSame('Policies for information security', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function auditorNotesSheetHasEmptyColumnsForAuditor(): void
    {
        $control = $this->buildControlStub('5.1', 'Test control');

        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([$control]);

        $generator = new SoaWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Auditor-Notes');
        self::assertNotNull($sheet);

        // Column E (Auditor Observation) must be blank for data rows
        self::assertNull($sheet->getCell('E2')->getValue());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function buildTenantStub(): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getName')->willReturn('ALVA Test Organisation');
        return $tenant;
    }

    private function buildControlStub(string $controlId, string $name): Control
    {
        $control = $this->createStub(Control::class);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getCategory')->willReturn('Organisational');
        $control->method('isApplicable')->willReturn(true);
        $control->method('getJustification')->willReturn(null);
        $control->method('getImplementationStatus')->willReturn('in_progress');
        $control->method('getImplementationNotes')->willReturn(null);
        $control->method('getImplementationPercentage')->willReturn(50);
        $control->method('getLastReviewDate')->willReturn(null);
        $control->method('getNextReviewDate')->willReturn(null);
        $control->method('getTargetDate')->willReturn(null);
        $control->method('getEffectiveResponsiblePerson')->willReturn('Max Mustermann');
        $control->method('getEffectiveness')->willReturn(null);
        $control->method('getControlMaturity')->willReturn(null);
        $control->method('getLastEffectivenessTest')->willReturn(null);
        $control->method('getEvidenceDocuments')->willReturn(new ArrayCollection());
        return $control;
    }
}
