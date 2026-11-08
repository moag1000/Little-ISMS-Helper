<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Entity\Asset;
use App\Util\IsmsScopeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** ISO 27001 4.3: numbers that describe the ISMS count only what lies inside the scope. */
final class IsmsScopeFilterTest extends TestCase
{
    #[Test]
    public function inScopeKeepsOnlyRecordsInsideTheScope(): void
    {
        $inside = new Asset();
        $outside = (new Asset())->setInIsmsScope(false);

        self::assertSame([$inside], IsmsScopeFilter::inScope([$outside, $inside]));
    }
}
