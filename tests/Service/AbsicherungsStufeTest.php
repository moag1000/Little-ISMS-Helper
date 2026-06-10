<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AbsicherungsStufe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WS-1 Vocabulary consolidation — AbsicherungsStufe enum.
 *
 * Verifies:
 *  - tiersForLevel() returns the cumulative tier-set per Absicherungsstufe
 *    (basis ⊂ standard ⊂ kern semantics from BSI-Grundschutz).
 *  - normalize() maps legacy anforderungsTyp raw strings to canonical values.
 */
final class AbsicherungsStufeTest extends TestCase
{
    #[Test]
    public function levelMapsToTierSet(): void
    {
        self::assertSame(['basis'], AbsicherungsStufe::tiersForLevel('basis'));
        self::assertSame(['basis', 'standard'], AbsicherungsStufe::tiersForLevel('standard'));
        self::assertSame(['basis', 'standard', 'hoch'], AbsicherungsStufe::tiersForLevel('kern'));
    }

    #[Test]
    public function unknownLevelFallsBackToBasisOnly(): void
    {
        self::assertSame(['basis'], AbsicherungsStufe::tiersForLevel(''));
        self::assertSame(['basis'], AbsicherungsStufe::tiersForLevel('unknown'));
    }

    #[Test]
    public function normalizesLegacyAnforderungsTyp(): void
    {
        self::assertSame('hoch', AbsicherungsStufe::normalize('hoch'));
        self::assertSame('hoch', AbsicherungsStufe::normalize('erhoeht'));
        self::assertSame('hoch', AbsicherungsStufe::normalize('erhöht'));
        self::assertSame('basis', AbsicherungsStufe::normalize('basis'));
        self::assertSame('standard', AbsicherungsStufe::normalize('standard'));
        self::assertNull(AbsicherungsStufe::normalize('garbage'));
        self::assertNull(AbsicherungsStufe::normalize(null));
        self::assertNull(AbsicherungsStufe::normalize(''));
    }
}
