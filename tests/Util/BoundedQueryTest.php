<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\BoundedQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BoundedQueryTest extends TestCase
{
    #[Test]
    public function strictValueOutsideItsRangeIs400(): void
    {
        self::assertSame(12, BoundedQuery::int(new Request(), 'period', 12, 1, 36));
        self::assertSame(24, BoundedQuery::int(new Request(['period' => '24']), 'period', 12, 1, 36));
        foreach (['100000', '0', '-3', 'abc', '1e9', '12.5'] as $bad) {
            try {
                BoundedQuery::int(new Request(['period' => $bad]), 'period', 12, 1, 36);
                self::fail('period=' . $bad . ' accepted');
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(BadRequestHttpException::class);
        BoundedQuery::int(new Request(['period' => ['1']]), 'period', 12, 1, 36);
    }

    #[Test]
    public function lenientValueIsClamped(): void
    {
        self::assertSame(100, BoundedQuery::clamped(new Request(), 'limit', 100, 1, 1000));
        self::assertSame(1000, BoundedQuery::clamped(new Request(['limit' => '99999999999']), 'limit', 100, 1, 1000));
        self::assertSame(1, BoundedQuery::clamped(new Request(['limit' => '-5']), 'limit', 100, 1, 1000));
        self::assertSame(100, BoundedQuery::clamped(new Request(['limit' => 'x']), 'limit', 100, 1, 1000));
        self::assertSame(50, BoundedQuery::clamp('500', 1, 50));
    }
}
