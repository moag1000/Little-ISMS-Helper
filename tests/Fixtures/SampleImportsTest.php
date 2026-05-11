<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the sample XLSX import fixtures created for F2.11.
 *
 * These fixtures live in fixtures/sample-imports/ and are used by:
 *   - BulkImportControllerTest::testUploadAcceptsValidXlsx
 *   - Developer documentation / onboarding
 *
 * Column-header mix (DE+EN) is intentional to demonstrate the heuristic-mapper.
 */
class SampleImportsTest extends TestCase
{
    private static string $fixtureDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureDir = dirname(__DIR__, 2) . '/fixtures/sample-imports';
    }

    // ── assets-sample.xlsx ────────────────────────────────────────────────────

    #[Test]
    public function testAssetsSampleXlsxIsValid(): void
    {
        $path = self::$fixtureDir . '/assets-sample.xlsx';

        $this->assertFileExists($path, 'assets-sample.xlsx must exist (F2.11).');

        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();

        // Row count: 1 header + 10 data rows = 11
        $this->assertSame(11, $sheet->getHighestRow(), 'Expected 1 header + 10 data rows.');

        // Column count: 8 columns (A–H)
        $this->assertSame('H', $sheet->getHighestColumn(), 'Expected 8 columns (A–H).');

        // Header check — mix of DE and EN column names
        $expectedHeaders = [
            'A' => 'Name',
            'B' => 'Type',
            'C' => 'Owner',
            'D' => 'Klassifizierung',
            'E' => 'Confidentiality',
            'F' => 'Integrity',
            'G' => 'Availability',
            'H' => 'Description',
        ];

        foreach ($expectedHeaders as $col => $expectedHeader) {
            $this->assertSame(
                $expectedHeader,
                $sheet->getCell($col . '1')->getValue(),
                sprintf('Column %s header mismatch.', $col),
            );
        }

        // All 10 data rows must have a non-empty Name in column A
        for ($row = 2; $row <= 11; $row++) {
            $this->assertNotEmpty(
                $sheet->getCell('A' . $row)->getValue(),
                sprintf('Row %d: Name (column A) must not be empty.', $row),
            );
        }

        // CIA values (E/F/G) must be integers between 1 and 5
        for ($row = 2; $row <= 11; $row++) {
            foreach (['E', 'F', 'G'] as $ciaCol) {
                $value = (int) $sheet->getCell($ciaCol . $row)->getValue();
                $this->assertGreaterThanOrEqual(1, $value, sprintf('Row %d col %s: CIA value must be >= 1.', $row, $ciaCol));
                $this->assertLessThanOrEqual(5, $value, sprintf('Row %d col %s: CIA value must be <= 5.', $row, $ciaCol));
            }
        }
    }

    // ── suppliers-sample.xlsx ─────────────────────────────────────────────────

    #[Test]
    public function testSuppliersSampleXlsxIsValid(): void
    {
        $path = self::$fixtureDir . '/suppliers-sample.xlsx';

        $this->assertFileExists($path, 'suppliers-sample.xlsx must exist (F2.11).');

        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();

        // Row count: 1 header + 8 data rows = 9
        $this->assertSame(9, $sheet->getHighestRow(), 'Expected 1 header + 8 data rows.');

        // Column count: 5 columns (A–E)
        $this->assertSame('E', $sheet->getHighestColumn(), 'Expected 5 columns (A–E).');

        // Header check — mix of DE and EN column names
        $expectedHeaders = [
            'A' => 'Firma',
            'B' => 'Email',
            'C' => 'Kritikalität',
            'D' => 'DORA-relevant',
            'E' => 'Description',
        ];

        foreach ($expectedHeaders as $col => $expectedHeader) {
            $this->assertSame(
                $expectedHeader,
                $sheet->getCell($col . '1')->getValue(),
                sprintf('Column %s header mismatch.', $col),
            );
        }

        // All 8 data rows must have a non-empty Firma (name)
        for ($row = 2; $row <= 9; $row++) {
            $this->assertNotEmpty(
                $sheet->getCell('A' . $row)->getValue(),
                sprintf('Row %d: Firma (column A) must not be empty.', $row),
            );
        }

        // No real competitor/vendor names (spot-check: all names contain "example" placeholder)
        for ($row = 2; $row <= 9; $row++) {
            $name = (string) $sheet->getCell('A' . $row)->getValue();
            $this->assertDoesNotMatchRegularExpression(
                '/\b(aws|azure|google cloud|microsoft|salesforce|servicenow)\b/i',
                $name,
                sprintf('Row %d: Supplier name must not contain real vendor names.', $row),
            );
        }
    }

    // ── controls-sample.xlsx ──────────────────────────────────────────────────

    #[Test]
    public function testControlsSampleXlsxIsValid(): void
    {
        $path = self::$fixtureDir . '/controls-sample.xlsx';

        $this->assertFileExists($path, 'controls-sample.xlsx must exist (F2.11).');

        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();

        // Row count: 1 header + 12 data rows = 13
        $this->assertSame(13, $sheet->getHighestRow(), 'Expected 1 header + 12 data rows.');

        // Column count: 4 columns (A–D)
        $this->assertSame('D', $sheet->getHighestColumn(), 'Expected 4 columns (A–D).');

        // Header check
        $expectedHeaders = ['A' => 'Annex', 'B' => 'Title', 'C' => 'Applicability', 'D' => 'Justification'];
        foreach ($expectedHeaders as $col => $expectedHeader) {
            $this->assertSame(
                $expectedHeader,
                $sheet->getCell($col . '1')->getValue(),
                sprintf('Column %s header mismatch.', $col),
            );
        }

        // All 12 Annex-A identifiers must match ISO 27001 format: "A.X.Y" or "X.Y"
        $annexPattern = '/^(?:A\.)?(\d+\.\d+(?:\.\d+)?)$/i';
        for ($row = 2; $row <= 13; $row++) {
            $annexId = (string) $sheet->getCell('A' . $row)->getValue();
            $this->assertMatchesRegularExpression(
                $annexPattern,
                $annexId,
                sprintf('Row %d: Annex ID "%s" does not match ISO 27001 format.', $row, $annexId),
            );
        }

        // Applicability values must be one of the accepted values
        $validApplicability = ['applicable', 'not_applicable', 'not_determined'];
        for ($row = 2; $row <= 13; $row++) {
            $applicability = strtolower(trim((string) $sheet->getCell('C' . $row)->getValue()));
            $this->assertContains(
                $applicability,
                $validApplicability,
                sprintf('Row %d: Applicability "%s" is not a valid value.', $row, $applicability),
            );
        }

        // Verify all expected Annex identifiers are present
        $expectedAnnexIds = ['A.5.1', 'A.5.2', 'A.5.7', 'A.6.1', 'A.6.3', 'A.7.1', 'A.7.4', 'A.8.1', 'A.8.5', 'A.8.8', 'A.8.16', 'A.8.24'];
        $actualAnnexIds   = [];
        for ($row = 2; $row <= 13; $row++) {
            $actualAnnexIds[] = (string) $sheet->getCell('A' . $row)->getValue();
        }

        foreach ($expectedAnnexIds as $expectedId) {
            $this->assertContains(
                $expectedId,
                $actualAnnexIds,
                sprintf('Expected Annex ID "%s" not found in controls-sample.xlsx.', $expectedId),
            );
        }
    }
}
