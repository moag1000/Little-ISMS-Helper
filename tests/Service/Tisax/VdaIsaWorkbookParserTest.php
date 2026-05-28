<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\Dto\VdaIsaControlRow;
use App\Service\Tisax\VdaIsaWorkbookParser;
use App\Service\Tisax\VdaIsaWorkbookValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for VdaIsaWorkbookParser.
 *
 * Uses the anonymised stub XLSX at tests/fixtures/vda_isa_stub.xlsx
 * which contains 35 synthetic control rows (no ENX-copyrighted content).
 */
class VdaIsaWorkbookParserTest extends TestCase
{
    private VdaIsaWorkbookParser $parser;
    private VdaIsaWorkbookValidator $validator;
    private string $stubFixture;

    protected function setUp(): void
    {
        $this->parser    = new VdaIsaWorkbookParser(new NullLogger());
        $this->validator = new VdaIsaWorkbookValidator();
        $this->stubFixture = dirname(__DIR__, 2) . '/fixtures/vda_isa_stub.xlsx';
    }

    #[Test]
    public function it_parses_stub_fixture_successfully(): void
    {
        $result = $this->parser->parse($this->stubFixture);

        self::assertGreaterThanOrEqual(30, $result->getControlCount());
        self::assertSame('ISA', $result->sheetName);
        self::assertArrayHasKey('controlId', $result->detectedColumnMap);
        self::assertArrayHasKey('titleEn', $result->detectedColumnMap);
    }

    #[Test]
    public function it_returns_vda_isa_control_row_dtos(): void
    {
        $result = $this->parser->parse($this->stubFixture);

        $first = $result->controls[0];
        self::assertInstanceOf(VdaIsaControlRow::class, $first);
        self::assertNotEmpty($first->controlId);
        self::assertNotEmpty($first->title);
    }

    #[Test]
    public function it_detects_control_id_with_dot_notation(): void
    {
        $result = $this->parser->parse($this->stubFixture);

        foreach ($result->controls as $ctrl) {
            self::assertMatchesRegularExpression('/^\d{1,2}(\.\d{1,2}){1,3}$/', $ctrl->controlId,
                sprintf('Control ID "%s" does not match dot-notation pattern', $ctrl->controlId));
        }
    }

    #[Test]
    public function it_derives_correct_tier_for_domain_8(): void
    {
        // Create a synthetic row with domain 8
        $row = new VdaIsaControlRow(
            controlId: '8.1.1',
            title: 'Test',
            titleEn: null,
            description: null,
            mustLevel: null,
            shouldLevel: null,
            highLevel: null,
            veryHighLevel: null,
            iso27001Ref: null,
            auditEvidenceHint: null,
            rawRowIndex: 2,
        );

        self::assertSame('prototype_protection', $row->getTier());
    }

    #[Test]
    public function it_derives_information_security_tier_for_domain_1(): void
    {
        $row = new VdaIsaControlRow(
            controlId: '1.1.1',
            title: 'Test',
            titleEn: null,
            description: null,
            mustLevel: null,
            shouldLevel: null,
            highLevel: null,
            veryHighLevel: null,
            iso27001Ref: null,
            auditEvidenceHint: null,
            rawRowIndex: 2,
        );

        self::assertSame('information_security', $row->getTier());
    }

    #[Test]
    public function validator_accepts_stub_fixture(): void
    {
        $result     = $this->parser->parse($this->stubFixture);
        $validation = $this->validator->validate($result);

        self::assertTrue($validation['ok'], implode('; ', $validation['errors']));
    }

    #[Test]
    public function it_throws_on_missing_file(): void
    {
        $this->expectException(\ErrorException::class);
        $this->parser->parse('/nonexistent/path/workbook.xlsx');
    }

    #[Test]
    public function validator_rejects_result_with_too_few_rows(): void
    {
        $controls = [];
        for ($i = 0; $i < 10; $i++) {
            $controls[] = new VdaIsaControlRow(
                controlId: '1.1.' . ($i + 1),
                title: 'test',
                titleEn: null,
                description: null,
                mustLevel: null,
                shouldLevel: null,
                highLevel: null,
                veryHighLevel: null,
                iso27001Ref: null,
                auditEvidenceHint: null,
                rawRowIndex: $i + 2,
            );
        }

        $smallResult = new \App\Service\Tisax\Dto\ParsedWorkbookResult(
            controls: $controls,
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: ['controlId' => 0, 'titleEn' => 1],
        );

        $validation = $this->validator->validate($smallResult);

        self::assertFalse($validation['ok']);
        self::assertNotEmpty($validation['errors']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DDE (formula-injection) sanitization tests
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testCellValueStartingWithEqualsIsPrefixedWithApostrophe(): void
    {
        $parser = new VdaIsaWorkbookParser(new NullLogger());

        // Use reflection to call the private sanitizeCellValue helper
        $method = new \ReflectionMethod($parser, 'sanitizeCellValue');

        $payload = '=cmd|\'/c calc\'!A0';
        $result  = $method->invoke($parser, $payload);

        self::assertStringStartsWith("'", $result, 'DDE payload starting with = must be prefixed with apostrophe');
        self::assertSame("'" . $payload, $result);
    }

    #[Test]
    public function testCellValueStartingWithPlusIsPrefixedWithApostrophe(): void
    {
        $parser = new VdaIsaWorkbookParser(new NullLogger());
        $method = new \ReflectionMethod($parser, 'sanitizeCellValue');

        foreach (['+', '-', '@', "\t", "\r"] as $trigger) {
            $payload = $trigger . 'dangerous payload';
            $result  = $method->invoke($parser, $payload);

            self::assertStringStartsWith(
                "'",
                $result,
                sprintf('DDE trigger character %s must be neutralised by apostrophe prefix', json_encode($trigger)),
            );
            self::assertSame("'" . $payload, $result);
        }
    }

    #[Test]
    public function testNormalTextValueIsUnchanged(): void
    {
        $parser = new VdaIsaWorkbookParser(new NullLogger());
        $method = new \ReflectionMethod($parser, 'sanitizeCellValue');

        $safeValues = [
            'ISO 27001 A.5.9',
            'Control question text',
            '1.2.3',
            'Normal description',
            '',
        ];

        foreach ($safeValues as $value) {
            $result = $method->invoke($parser, $value);
            self::assertSame($value, $result, "Safe value '{$value}' must not be modified");
        }
    }

    #[Test]
    public function validator_rejects_result_with_high_invalid_id_ratio(): void
    {
        $controls = [];
        for ($i = 0; $i < 35; $i++) {
            // First 28 rows have invalid IDs (80% > 20% threshold)
            $id = $i < 28 ? 'INVALID-ID-' . $i : '1.1.' . ($i + 1);
            $controls[] = new VdaIsaControlRow(
                controlId: $id,
                title: 'test',
                titleEn: null,
                description: null,
                mustLevel: null,
                shouldLevel: null,
                highLevel: null,
                veryHighLevel: null,
                iso27001Ref: null,
                auditEvidenceHint: null,
                rawRowIndex: $i + 2,
            );
        }

        $result = new \App\Service\Tisax\Dto\ParsedWorkbookResult(
            controls: $controls,
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: ['controlId' => 0, 'titleEn' => 1],
        );

        $validation = $this->validator->validate($result);

        self::assertFalse($validation['ok']);
    }
}
