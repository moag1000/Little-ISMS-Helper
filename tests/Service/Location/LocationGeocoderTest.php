<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location;
use App\Service\Location\LocationGeocoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the bundled tables in fixtures/geo (GeoNames, CC BY 4.0).
 */
final class LocationGeocoderTest extends TestCase
{
    private LocationGeocoder $geocoder;

    protected function setUp(): void
    {
        $this->geocoder = new LocationGeocoder(dirname(__DIR__, 3) . '/fixtures/geo');
    }

    #[Test]
    public function germanPostalCodeIsPrecise(): void
    {
        $point = $this->geocoder->locate('80331', 'München', 'Deutschland');

        self::assertNotNull($point);
        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $point->source);
        self::assertEqualsWithDelta(48.14, $point->latitude, 0.05);
        self::assertEqualsWithDelta(11.57, $point->longitude, 0.05);
    }

    #[Test]
    public function austrianAndSwissPostalCodesAreResolvedWithCountryNames(): void
    {
        $vienna = $this->geocoder->locate('1010', 'Wien', 'Österreich');
        $zurich = $this->geocoder->locate('8001', null, 'Schweiz');

        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $vienna?->source);
        self::assertEqualsWithDelta(48.21, $vienna->latitude, 0.05);
        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $zurich?->source);
        self::assertEqualsWithDelta(47.37, $zurich->latitude, 0.05);
    }

    #[Test]
    public function postalCodeWithoutCountryNeedsTheCity(): void
    {
        // 4-digit codes exist in AT and CH; the city decides.
        $withCity = $this->geocoder->locate('8001', 'Zürich', null);
        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $withCity?->source);
        self::assertEqualsWithDelta(8.54, $withCity->longitude, 0.05);
    }

    #[Test]
    public function cityOutsideDachUsesTheWorldTable(): void
    {
        $point = $this->geocoder->locate(null, 'Lyon', 'Frankreich');

        self::assertSame(LocationGeocoder::SOURCE_CITY, $point?->source);
        self::assertEqualsWithDelta(45.75, $point->latitude, 0.1);
        self::assertEqualsWithDelta(4.85, $point->longitude, 0.1);
    }

    #[Test]
    public function germanExonymsAndTheLargestPlaceWin(): void
    {
        self::assertEqualsWithDelta(45.46, $this->geocoder->locate(null, 'Mailand', 'Italien')?->latitude, 0.1);
        self::assertEqualsWithDelta(50.09, $this->geocoder->locate(null, 'Prag', 'Tschechien')?->latitude, 0.1);
        // "Querétaro" is also a village in Chiapas; the city wins.
        self::assertEqualsWithDelta(-100.39, $this->geocoder->locate(null, 'Querétaro', 'Mexiko')?->longitude, 0.1);
    }

    #[Test]
    public function unknownCityFallsBackToTheCountry(): void
    {
        $point = $this->geocoder->locate(null, 'Nirgendwo-am-See', 'India');

        self::assertSame(LocationGeocoder::SOURCE_COUNTRY, $point?->source);
        self::assertGreaterThan(5, $point->latitude);
        self::assertLessThan(35, $point->latitude);
    }

    #[Test]
    public function nothingKnownGivesNoPoint(): void
    {
        self::assertNull($this->geocoder->locate(null, null, null));
        self::assertNull($this->geocoder->locate(null, 'Atlantis', 'Atlantis'));
    }

    #[Test]
    public function countryNamesCodesAndAliasesAreNormalised(): void
    {
        self::assertSame('DE', $this->geocoder->countryCode('Germany'));
        self::assertSame('DE', $this->geocoder->countryCode('DEU'));
        self::assertSame('DE', $this->geocoder->countryCode('de'));
        self::assertSame('AT', $this->geocoder->countryCode('Österreich'));
        self::assertSame('CZ', $this->geocoder->countryCode('Tschechien'));
        self::assertSame('US', $this->geocoder->countryCode('USA'));
        self::assertNull($this->geocoder->countryCode(''));
    }

    #[Test]
    public function applyKeepsManualCoordinates(): void
    {
        $location = (new Location())->setPostalCode('80331')->setCountry('DE')
            ->setLatitude(1.0)->setLongitude(2.0)->setGeoSource(LocationGeocoder::SOURCE_MANUAL);

        self::assertFalse($this->geocoder->fillCoordinates($location));
        self::assertSame(1.0, $location->getLatitude());
    }

    #[Test]
    public function applyFillsAndClearsCoordinates(): void
    {
        $location = (new Location())->setPostalCode('60311')->setCity('Frankfurt am Main')->setCountry('Deutschland');

        self::assertTrue($this->geocoder->fillCoordinates($location));
        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $location->getGeoSource());
        self::assertEqualsWithDelta(50.11, $location->getLatitude(), 0.05);

        $location->setPostalCode(null)->setCity(null)->setCountry(null);
        self::assertTrue($this->geocoder->fillCoordinates($location));
        self::assertFalse($location->hasCoordinates());
        self::assertNull($location->getGeoSource());
    }
}
