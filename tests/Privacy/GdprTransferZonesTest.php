<?php

declare(strict_types=1);

namespace App\Tests\Privacy;

use App\Privacy\GdprTransferZones;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GDPR transfer zones come from config/gdpr_transfer_zones.yaml, the one
 * country list for the map, DORA RoI and the framework recommendations.
 */
final class GdprTransferZonesTest extends TestCase
{
    #[Test]
    public function classifiesCountriesByTransferZone(): void
    {
        self::assertSame(GdprTransferZones::EEA, GdprTransferZones::zoneOf('DE'));
        self::assertSame(GdprTransferZones::EEA, GdprTransferZones::zoneOf('no'), 'EEA member outside the EU, case-insensitive');
        self::assertSame(GdprTransferZones::ADEQUACY, GdprTransferZones::zoneOf('CH'));
        self::assertSame(GdprTransferZones::ADEQUACY, GdprTransferZones::zoneOf('GB'));
        self::assertSame(GdprTransferZones::DPF, GdprTransferZones::zoneOf('US'));
        self::assertSame(GdprTransferZones::THIRD, GdprTransferZones::zoneOf('CN'));
        self::assertSame(GdprTransferZones::UNKNOWN, GdprTransferZones::zoneOf(null));
        self::assertSame(GdprTransferZones::UNKNOWN, GdprTransferZones::zoneOf('Deutschland'));
    }

    #[Test]
    public function separatesEuFromEea(): void
    {
        self::assertTrue(GdprTransferZones::isEu('AT'));
        self::assertFalse(GdprTransferZones::isEu('NO'));
        self::assertTrue(GdprTransferZones::isEea('NO'));
        self::assertFalse(GdprTransferZones::isEea('CH'));
    }

    #[Test]
    public function worseReturnsTheMoreDemandingZone(): void
    {
        self::assertSame(GdprTransferZones::THIRD, GdprTransferZones::worse(GdprTransferZones::EEA, GdprTransferZones::THIRD));
        self::assertSame(GdprTransferZones::DPF, GdprTransferZones::worse(GdprTransferZones::DPF, GdprTransferZones::ADEQUACY));
    }

    #[Test]
    public function listCarriesAReviewDate(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', GdprTransferZones::asOf());
        self::assertArrayNotHasKey('CN', GdprTransferZones::all(), 'plain third countries are not listed');
    }
}
