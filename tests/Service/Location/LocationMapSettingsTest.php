<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Tenant;
use App\Service\Location\LocationMapSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocationMapSettingsTest extends TestCase
{
    #[Test]
    public function defaultsCompareWithoutNamesRoundToTheCityAndKeepAllChecks(): void
    {
        $settings = LocationMapSettings::of(new Tenant());

        self::assertTrue($settings->comparesPeers());
        self::assertFalse($settings->namesOwners());
        self::assertSame(48.14, $settings->display(48.137154));
        self::assertSame([], $settings->disabledChecks);
    }

    #[Test]
    public function unknownValuesFallBackAndTheOwnerCheckCannotBeSwitchedOff(): void
    {
        $settings = LocationMapSettings::fromArray([
            'peer_mode' => 'everyone',
            'precision' => 'street',
            'disabled_checks' => ['owner', 'camera', 'nonsense'],
        ]);

        self::assertSame(LocationMapSettings::PEER_ANONYMOUS, $settings->peerMode);
        self::assertSame(LocationMapSettings::PRECISION_CITY, $settings->precision);
        self::assertSame(['camera'], $settings->disabledChecks);
        self::assertTrue($settings->isCheckActive('owner'));
    }

    #[Test]
    public function exactPrecisionKeepsCoordinates(): void
    {
        $settings = new LocationMapSettings(precision: LocationMapSettings::PRECISION_EXACT);

        self::assertSame(48.137154, $settings->display(48.137154));
        self::assertNull($settings->display(null));
    }
}
