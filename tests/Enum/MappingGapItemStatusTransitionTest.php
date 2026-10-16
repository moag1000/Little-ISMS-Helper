<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\MappingGapItemStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappingGapItemStatusTransitionTest extends TestCase
{
    /** @return iterable<string, array{S, S, bool}> */
    public static function moves(): iterable
    {
        yield 'identified → planned' => [S::Identified, S::Planned, true];
        yield 'identified → resolved (skip work)' => [S::Identified, S::Resolved, false];
        yield 'planned → in_progress' => [S::Planned, S::InProgress, true];
        yield 'in_progress → resolved' => [S::InProgress, S::Resolved, true];
        yield 'resolved → in_progress (reopen)' => [S::Resolved, S::InProgress, true];
        yield 'resolved → wont_fix' => [S::Resolved, S::WontFix, false];
        yield 'resolved → planned' => [S::Resolved, S::Planned, false];
        yield 'wont_fix → identified (reopen)' => [S::WontFix, S::Identified, true];
        yield 'wont_fix → resolved' => [S::WontFix, S::Resolved, false];
        yield 'same status is a no-op' => [S::Resolved, S::Resolved, true];
    }

    #[Test]
    #[DataProvider('moves')]
    public function enforcesTransitionMatrix(S $from, S $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    #[Test]
    #[DataProvider('moves')]
    public function transitionErrorMatchesMatrix(S $from, S $to, bool $allowed): void
    {
        $error = $from->transitionError($to);
        if ($allowed) {
            self::assertNull($error);

            return;
        }
        self::assertStringContainsString('is not allowed', (string) $error);
    }

    #[Test]
    public function unknownStatusYieldsListOfValidValues(): void
    {
        self::assertSame(
            'Invalid status. Must be one of: identified, planned, in_progress, resolved, wont_fix',
            S::Identified->transitionError(null),
        );
    }
}
