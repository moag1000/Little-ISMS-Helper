<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Dto;

use App\Service\Import\Dto\ParsedSpreadsheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ParsedSpreadsheet DTO — basic invariants.
 */
final class ParsedSpreadsheetTest extends TestCase
{
    #[Test]
    public function testEmptySheetIsDetected(): void
    {
        $dto = new ParsedSpreadsheet(
            headers: [],
            rows: [],
            warnings: [],
            sheetName: 'Sheet1',
            totalRowCount: 0,
        );

        self::assertTrue($dto->isEmpty());
        self::assertFalse($dto->hasWarnings());
        self::assertSame('Sheet1', $dto->sheetName);
        self::assertSame(0, $dto->totalRowCount);
    }

    #[Test]
    public function testNonEmptySheetIsNotEmpty(): void
    {
        $dto = new ParsedSpreadsheet(
            headers: ['Name', 'Type'],
            rows: [['Name' => 'Server A', 'Type' => 'Hardware']],
            warnings: [],
            sheetName: 'Assets',
            totalRowCount: 1,
        );

        self::assertFalse($dto->isEmpty());
        self::assertFalse($dto->hasWarnings());
        self::assertCount(2, $dto->headers);
        self::assertCount(1, $dto->rows);
    }

    #[Test]
    public function testWarningsAreReported(): void
    {
        $dto = new ParsedSpreadsheet(
            headers: ['Name'],
            rows: [],
            warnings: ['Merged cell A1:B2 detected.'],
            sheetName: 'Sheet1',
            totalRowCount: 0,
        );

        self::assertTrue($dto->hasWarnings());
        self::assertCount(1, $dto->warnings);
        self::assertStringContainsString('Merged cell', $dto->warnings[0]);
    }

    #[Test]
    public function testTotalRowCountMatchesRowsArray(): void
    {
        $rows = [
            ['Name' => 'A', 'Type' => 'x'],
            ['Name' => 'B', 'Type' => 'y'],
            ['Name' => 'C', 'Type' => 'z'],
        ];

        $dto = new ParsedSpreadsheet(
            headers: ['Name', 'Type'],
            rows: $rows,
            warnings: [],
            sheetName: 'Data',
            totalRowCount: count($rows),
        );

        self::assertSame(3, $dto->totalRowCount);
        self::assertCount(3, $dto->rows);
    }

    #[Test]
    public function testPropertiesAreReadonly(): void
    {
        $dto = new ParsedSpreadsheet(
            headers: ['Col1'],
            rows: [],
            warnings: [],
            sheetName: 'Test',
            totalRowCount: 0,
        );

        // Verify readonly enforcement via reflection
        $ref = new \ReflectionClass($dto);
        foreach (['headers', 'rows', 'warnings', 'sheetName', 'totalRowCount'] as $prop) {
            self::assertTrue(
                $ref->getProperty($prop)->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $prop),
            );
        }
    }
}
