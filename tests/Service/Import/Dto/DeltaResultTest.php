<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Dto;

use App\Service\Import\Dto\DeltaResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeltaResult DTO — summary auto-computation and invariants.
 */
final class DeltaResultTest extends TestCase
{
    #[Test]
    public function testSummaryAutoComputesFromArrays(): void
    {
        $creates   = [
            ['rowNumber' => 1, 'data' => ['name' => 'A'], 'entityId' => null, 'oldValues' => null, 'newValues' => ['name' => 'A'], 'diff' => null],
            ['rowNumber' => 2, 'data' => ['name' => 'B'], 'entityId' => null, 'oldValues' => null, 'newValues' => ['name' => 'B'], 'diff' => null],
        ];
        $updates   = [
            ['rowNumber' => 3, 'data' => ['name' => 'C'], 'entityId' => 42, 'oldValues' => ['name' => 'X'], 'newValues' => ['name' => 'C'], 'diff' => ['name' => ['old' => 'X', 'new' => 'C']]],
        ];
        $unchanged = [
            ['rowNumber' => 4, 'data' => ['name' => 'D'], 'entityId' => 99, 'oldValues' => ['name' => 'D'], 'newValues' => ['name' => 'D'], 'diff' => []],
        ];
        $deletes   = [
            ['entityId' => 7, 'snapshot' => ['name' => 'Old']],
        ];
        $errors    = [
            ['rowNumber' => 5, 'data' => ['name' => ''], 'errors' => ['Name must not be blank.']],
        ];

        $result = new DeltaResult($creates, $updates, $unchanged, $deletes, $errors);

        self::assertSame(2, $result->summary['creates']);
        self::assertSame(1, $result->summary['updates']);
        self::assertSame(1, $result->summary['unchanged']);
        self::assertSame(1, $result->summary['deletes']);
        self::assertSame(1, $result->summary['errors']);
        // total = creates + updates + unchanged + errors (deletes are DB-side, not rows)
        self::assertSame(5, $result->summary['total']);
    }

    #[Test]
    public function testEmptyArraysProduceZeroSummary(): void
    {
        $result = new DeltaResult([], [], [], [], []);

        self::assertSame(0, $result->summary['creates']);
        self::assertSame(0, $result->summary['updates']);
        self::assertSame(0, $result->summary['unchanged']);
        self::assertSame(0, $result->summary['deletes']);
        self::assertSame(0, $result->summary['errors']);
        self::assertSame(0, $result->summary['total']);
    }
}
