<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Service\Suggestion\Kind\ControlEffectivenessKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ControlEffectivenessKindTest extends TestCase
{
    #[Test]
    public function incidentsNamingTheControlAsFailedCapItsRating(): void
    {
        self::assertNull(ControlEffectivenessKind::ratingForFailures(0));
        self::assertSame('partially_effective', ControlEffectivenessKind::ratingForFailures(1));
        self::assertSame('ineffective', ControlEffectivenessKind::ratingForFailures(3));
        self::assertSame('effective', ControlEffectivenessKind::ratingFor(85.0));
    }
}
