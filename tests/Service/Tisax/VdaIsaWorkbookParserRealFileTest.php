<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\VdaIsaWorkbookParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests against the official ENX ISA 6 workbooks.
 *
 * These tests only run when the fixture files are present.
 * The files are NOT committed — download them manually:
 *
 *   curl -sL https://portal.enx.com/isa6-en.xlsx -o tests/fixtures/vda_isa_6_en_official.xlsx
 *   curl -sL https://portal.enx.com/isa6-de.xlsx  -o tests/fixtures/vda_isa_6_de_official.xlsx
 *
 * See docs/tisax/VDA_ISA_6_PARSER_NOTES.md for full structure documentation.
 */
class VdaIsaWorkbookParserRealFileTest extends TestCase
{
    private VdaIsaWorkbookParser $parser;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->parser      = new VdaIsaWorkbookParser(new NullLogger());
        $this->fixturesDir = dirname(__DIR__, 2) . '/fixtures';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // EN workbook
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function en_workbook_parses_header_row_correctly(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        self::assertSame('Information Security', $result->sheetName,
            'Sheet resolved to wrong name — expected "Information Security"');
        self::assertSame(2, $result->headerRowIndex,
            'Header row should be row 2 (row 1 is merged title banner)');
        self::assertArrayHasKey('controlId', $result->detectedColumnMap);
        self::assertArrayHasKey('titleEn', $result->detectedColumnMap);
        self::assertSame(2, $result->detectedColumnMap['controlId'],
            'controlId should be col C (index 2) = "Control number"');
        self::assertSame(7, $result->detectedColumnMap['titleEn'],
            'titleEn should be col H (index 7) = "Control question"');
    }

    #[Test]
    public function en_workbook_extracts_at_least_40_controls(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        self::assertGreaterThanOrEqual(40, $result->getControlCount(),
            'ENX ISA 6 EN "Information Security" sheet should yield at least 40 controls');
    }

    #[Test]
    public function en_workbook_control_ids_follow_dot_notation(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        foreach ($result->controls as $ctrl) {
            self::assertMatchesRegularExpression(
                '/^\d{1,2}(\.\d{1,2}){1,3}$/',
                $ctrl->controlId,
                sprintf(
                    'Control ID "%s" (rawRow=%d) does not match dot-notation pattern — '
                    . 'likely a section header row that was not filtered',
                    $ctrl->controlId,
                    $ctrl->rawRowIndex,
                ),
            );
        }
    }

    #[Test]
    public function en_workbook_no_section_header_ids_present(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        $sectionLikeIds = array_filter(
            $result->controls,
            static fn ($c) => substr_count($c->controlId, '.') < 2,
        );

        self::assertEmpty(
            $sectionLikeIds,
            sprintf(
                'Section-level IDs should be filtered out. Found: %s',
                implode(', ', array_map(fn ($c) => $c->controlId, $sectionLikeIds)),
            ),
        );
    }

    #[Test]
    public function en_workbook_first_control_is_1_1_1(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        self::assertSame('1.1.1', $result->controls[0]->controlId,
            'First leaf control in "Information Security" sheet should be 1.1.1');
    }

    #[Test]
    public function en_workbook_iso27001_ref_column_populated(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        self::assertArrayHasKey('iso27001Ref', $result->detectedColumnMap,
            'ISO 27001 reference column should be detected');
        self::assertSame(15, $result->detectedColumnMap['iso27001Ref'],
            'iso27001Ref should be col P (index 15) = "Reference to other standards"');

        $withIso = array_filter($result->controls, static fn ($c) => $c->iso27001Ref !== null);
        self::assertGreaterThan(30, count($withIso),
            'At least 30 controls should have an ISO 27001 reference');
    }

    #[Test]
    public function en_workbook_maturity_columns_populated(): void
    {
        $this->requireFixture('vda_isa_6_en_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_en_official.xlsx'));

        self::assertArrayHasKey('mustLevel', $result->detectedColumnMap);
        self::assertSame(9, $result->detectedColumnMap['mustLevel'],
            'mustLevel should be col J (index 9) = "Requirements (must)"');

        $withMust = array_filter($result->controls, static fn ($c) => $c->mustLevel !== null);
        self::assertGreaterThan(30, count($withMust),
            'At least 30 controls should have must-level requirements');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DE workbook
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function de_workbook_parses_header_row_correctly(): void
    {
        $this->requireFixture('vda_isa_6_de_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_de_official.xlsx'));

        self::assertSame('Informationssicherheit', $result->sheetName,
            'Sheet resolved to wrong name — expected "Informationssicherheit"');
        self::assertSame(2, $result->headerRowIndex,
            'Header row should be row 2');
        self::assertArrayHasKey('controlId', $result->detectedColumnMap);
        self::assertArrayHasKey('titleDe', $result->detectedColumnMap);
        self::assertSame(2, $result->detectedColumnMap['controlId'],
            'controlId should be col C (index 2) = "Kontrollnummer"');
        self::assertSame(7, $result->detectedColumnMap['titleDe'],
            'titleDe should be col H (index 7) = "Kontrollfrage"');
    }

    #[Test]
    public function de_workbook_extracts_at_least_40_controls(): void
    {
        $this->requireFixture('vda_isa_6_de_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_de_official.xlsx'));

        self::assertGreaterThanOrEqual(40, $result->getControlCount(),
            'ENX ISA 6 DE "Informationssicherheit" sheet should yield at least 40 controls');
    }

    #[Test]
    public function de_workbook_control_ids_follow_dot_notation(): void
    {
        $this->requireFixture('vda_isa_6_de_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_de_official.xlsx'));

        foreach ($result->controls as $ctrl) {
            self::assertMatchesRegularExpression(
                '/^\d{1,2}(\.\d{1,2}){1,3}$/',
                $ctrl->controlId,
                sprintf(
                    'Control ID "%s" (rawRow=%d) does not match dot-notation pattern',
                    $ctrl->controlId,
                    $ctrl->rawRowIndex,
                ),
            );
        }
    }

    #[Test]
    public function de_workbook_iso27001_ref_column_populated(): void
    {
        $this->requireFixture('vda_isa_6_de_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_de_official.xlsx'));

        self::assertArrayHasKey('iso27001Ref', $result->detectedColumnMap,
            'ISO 27001 reference column should be detected for DE workbook');
        self::assertSame(15, $result->detectedColumnMap['iso27001Ref'],
            'iso27001Ref should be col P (index 15) = "Verweisung auf andere Normen"');

        $withIso = array_filter($result->controls, static fn ($c) => $c->iso27001Ref !== null);
        self::assertGreaterThan(30, count($withIso),
            'At least 30 DE controls should have an ISO 27001/norm reference');
    }

    #[Test]
    public function de_workbook_maturity_columns_populated(): void
    {
        $this->requireFixture('vda_isa_6_de_official.xlsx');

        $result = $this->parser->parse($this->fixturePath('vda_isa_6_de_official.xlsx'));

        self::assertArrayHasKey('mustLevel', $result->detectedColumnMap);
        self::assertSame(9, $result->detectedColumnMap['mustLevel'],
            'mustLevel should be col J (index 9) = "Anforderungen (muss)"');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function requireFixture(string $filename): void
    {
        if (!file_exists($this->fixturePath($filename))) {
            $this->markTestSkipped(
                sprintf(
                    'Official ENX VDA-ISA 6 workbook "%s" not present. '
                    . 'Download it with: curl -sL https://portal.enx.com/isa6-%s.xlsx -o tests/fixtures/%s',
                    $filename,
                    str_contains($filename, '_de_') ? 'de' : 'en',
                    $filename,
                ),
            );
        }
    }

    private function fixturePath(string $filename): string
    {
        return $this->fixturesDir . '/' . $filename;
    }
}
