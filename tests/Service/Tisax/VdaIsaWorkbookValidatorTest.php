<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\Dto\ParsedWorkbookResult;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use App\Service\Tisax\VdaIsaWorkbookValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VdaIsaWorkbookValidator.
 *
 * Pure-logic tests — no DB or EM needed.
 */
final class VdaIsaWorkbookValidatorTest extends TestCase
{
    private VdaIsaWorkbookValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new VdaIsaWorkbookValidator();
    }

    // ────────────────────────────────────────────────────────────────────────
    // validate() — hard failures
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function validate_rejects_too_few_rows(): void
    {
        $result = new ParsedWorkbookResult(
            controls: $this->buildControls(5),
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: ['controlId' => 0, 'titleDe' => 1],
        );

        $out = $this->validator->validate($result);

        self::assertFalse($out['ok']);
        self::assertNotEmpty($out['errors']);
        self::assertStringContainsString('Too few control rows', $out['errors'][0]);
    }

    #[Test]
    public function validate_rejects_high_ratio_of_malformed_ids(): void
    {
        // Build 40 controls where 30+ have malformed IDs
        $controls = [];
        for ($i = 0; $i < 10; $i++) {
            $controls[] = $this->makeRow('1.1.' . ($i + 1), $i + 1);
        }
        for ($i = 0; $i < 35; $i++) {
            $controls[] = $this->makeRow('BADID-' . $i, $i + 11);
        }

        $result = new ParsedWorkbookResult(
            controls: $controls,
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: ['controlId' => 0, 'titleDe' => 1],
        );

        $out = $this->validator->validate($result);

        self::assertFalse($out['ok']);
        self::assertTrue(
            count(array_filter($out['errors'], fn($e) => str_contains($e, 'do not match'))) > 0,
            'Expected malformed-ID error'
        );
    }

    #[Test]
    public function validate_accepts_valid_full_workbook(): void
    {
        $controls = $this->buildControls(70);

        $result = new ParsedWorkbookResult(
            controls: $controls,
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: [
                'controlId'   => 0,
                'titleDe'     => 1,
                'titleEn'     => 2,
                'iso27001Ref' => 3,
                'evidenceHint'=> 4,
                'description' => 5,
            ],
        );

        $out = $this->validator->validate($result);

        self::assertTrue($out['ok']);
        self::assertSame([], $out['errors']);
    }

    #[Test]
    public function validate_emits_warnings_for_missing_optional_columns(): void
    {
        $result = new ParsedWorkbookResult(
            controls: $this->buildControls(40),
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: ['controlId' => 0, 'titleDe' => 1],
        );

        $out = $this->validator->validate($result);

        // ok because no hard failures
        self::assertTrue($out['ok']);
        // but warnings about missing optional columns
        self::assertNotEmpty($out['warnings']);
        $warningText = implode(' ', $out['warnings']);
        self::assertStringContainsString('iso27001Ref', $warningText);
    }

    #[Test]
    public function validate_emits_warning_for_minority_malformed_ids(): void
    {
        // 50 good + 5 bad = 9% invalid → warning, not error
        $controls = $this->buildControls(50);
        for ($i = 0; $i < 5; $i++) {
            $controls[] = $this->makeRow('NOFORMAT', $i + 51);
        }

        $result = new ParsedWorkbookResult(
            controls: $controls,
            sheetName: 'ISA',
            headerRowIndex: 1,
            detectedColumnMap: ['controlId' => 0, 'titleDe' => 1],
        );

        $out = $this->validator->validate($result);

        self::assertTrue($out['ok']);
        self::assertSame([], $out['errors']);
        $warningText = implode(' ', $out['warnings']);
        self::assertStringContainsString('unusual format', $warningText);
    }

    // ────────────────────────────────────────────────────────────────────────
    // headerMapIsValid()
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function header_map_is_valid_returns_true_when_minimum_columns_present(): void
    {
        self::assertTrue($this->validator->headerMapIsValid(['controlId' => 0, 'titleDe' => 1]));
        self::assertTrue($this->validator->headerMapIsValid(['controlId' => 0, 'titleEn' => 2]));
    }

    #[Test]
    public function header_map_is_valid_returns_false_when_missing_control_id(): void
    {
        self::assertFalse($this->validator->headerMapIsValid(['titleDe' => 1]));
    }

    #[Test]
    public function header_map_is_valid_returns_false_when_missing_title(): void
    {
        self::assertFalse($this->validator->headerMapIsValid(['controlId' => 0]));
    }

    // ────────────────────────────────────────────────────────────────────────
    // validateRow()
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function validate_row_returns_null_for_valid_row(): void
    {
        $row = $this->makeRow('1.1.1', 2);
        self::assertNull($this->validator->validateRow($row));
    }

    #[Test]
    public function validate_row_returns_error_for_invalid_control_id(): void
    {
        $row = $this->makeRow('BADID', 5);
        $error = $this->validator->validateRow($row);
        self::assertNotNull($error);
        self::assertStringContainsString('Row 5', $error);
        self::assertStringContainsString('BADID', $error);
    }

    #[Test]
    public function validate_row_returns_error_when_title_exceeds_1000_chars(): void
    {
        $row = new VdaIsaControlRow(
            controlId: '1.1.1',
            title: str_repeat('x', 1001),
            titleEn: null,
            description: null,
            mustLevel: null,
            shouldLevel: null,
            highLevel: null,
            veryHighLevel: null,
            iso27001Ref: null,
            auditEvidenceHint: null,
            rawRowIndex: 3,
        );
        $error = $this->validator->validateRow($row);
        self::assertNotNull($error);
        self::assertStringContainsString('1000', $error);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /** @return list<VdaIsaControlRow> */
    private function buildControls(int $count): array
    {
        $controls = [];
        for ($i = 0; $i < $count; $i++) {
            // Vary domain to cover all three tiers
            $domain = ($i % 12) + 1;
            $controls[] = $this->makeRow(sprintf('%d.%d.%d', $domain, ($i % 5) + 1, ($i % 3) + 1), $i + 2);
        }
        return $controls;
    }

    private function makeRow(string $controlId, int $rowIndex): VdaIsaControlRow
    {
        return new VdaIsaControlRow(
            controlId: $controlId,
            title: 'Sample question ' . $rowIndex,
            titleEn: null,
            description: null,
            mustLevel: null,
            shouldLevel: null,
            highLevel: null,
            veryHighLevel: null,
            iso27001Ref: null,
            auditEvidenceHint: null,
            rawRowIndex: $rowIndex,
        );
    }
}
