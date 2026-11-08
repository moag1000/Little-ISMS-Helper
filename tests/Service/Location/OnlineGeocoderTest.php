<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Controller\Admin\SystemSettings\LocationMapSettingsController;
use App\Entity\Location;
use App\Entity\Tenant;
use App\Service\Location\LocationGeocoder;
use App\Service\Location\OnlineGeocoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OnlineGeocoderTest extends TestCase
{
    #[Test]
    public function offByDefaultAndSendsNothing(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));
        $geocoder = $this->geocoder($client);
        $location = (new Location())->setCity('Querétaro')->setCountry('Mexiko')->setGeoSource(LocationGeocoder::SOURCE_COUNTRY);

        self::assertSame(0, $geocoder->refine(new Tenant(), [$location]));
    }

    #[Test]
    public function sendsOnlyPostalCodeCityAndCountryCode(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $url;
            return new MockResponse('[{"lat":"20.5888","lon":"-100.3899"}]');
        });
        $tenant = (new Tenant())->setSettings([OnlineGeocoder::SETTINGS_KEY => ['online_geocoding' => true]]);
        $location = (new Location())->setName('Werk Geheim')->setAddress('Calle 1')->setPostalCode('76000')
            ->setCity('Querétaro')->setCountry('Mexiko')->setGeoSource(LocationGeocoder::SOURCE_COUNTRY);

        self::assertSame(1, $this->geocoder($client)->refine($tenant, [$location]));
        self::assertSame(LocationGeocoder::SOURCE_ONLINE, $location->getGeoSource());
        self::assertEqualsWithDelta(20.5888, $location->getLatitude(), 0.0001);

        parse_str((string) parse_url((string) $seen, PHP_URL_QUERY), $query);
        self::assertSame(['postalcode', 'city', 'countrycodes', 'format', 'limit'], array_keys($query));
        self::assertSame('mx', $query['countrycodes']);
        self::assertStringNotContainsString('Geheim', (string) $seen);
        self::assertStringNotContainsString('Calle', (string) $seen);
    }

    #[Test]
    public function preciseLocationsAreNotLookedUp(): void
    {
        $location = (new Location())->setPostalCode('80331')->setGeoSource(LocationGeocoder::SOURCE_POSTAL);

        self::assertFalse(OnlineGeocoder::needsRefinement($location));
    }

    #[Test]
    public function endpointMustBeHttpsWithoutCredentials(): void
    {
        self::assertTrue(LocationMapSettingsController::isValidEndpoint('https://nominatim.example.org/search'));
        self::assertFalse(LocationMapSettingsController::isValidEndpoint('http://nominatim.example.org/search'));
        self::assertFalse(LocationMapSettingsController::isValidEndpoint('https://user:pw@example.org/'));
        self::assertFalse(LocationMapSettingsController::isValidEndpoint('file:///etc/passwd'));
        self::assertFalse(LocationMapSettingsController::isValidEndpoint('not a url'));
    }

    private function geocoder(MockHttpClient $client): OnlineGeocoder
    {
        return new OnlineGeocoder(new \App\Tests\Support\PassThroughUserUrlClient($client), new LocationGeocoder(dirname(__DIR__, 3) . '/fixtures/geo'), new NullLogger());
    }
}
