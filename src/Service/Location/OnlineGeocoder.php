<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location;
use App\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional, opt-in lookup against a Nominatim-compatible endpoint, for
 * locations the offline tables only place at country level.
 *
 * Data minimisation: only postal code, city and country code are sent — no
 * street, no location name, no tenant or user data. Off by default; an admin
 * enables it per tenant at /admin/settings/location-map. The result is stored
 * on the location, so every address is looked up at most once.
 */
class OnlineGeocoder
{
    public const string SETTINGS_KEY = 'location_map';
    public const string DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /** Nominatim's public usage policy allows one request per second. */
    public const int MAX_PER_RUN = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LocationGeocoder $geocoder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{enabled: bool, endpoint: string} */
    public static function settings(Tenant $tenant): array
    {
        $raw = ($tenant->getSettings() ?? [])[self::SETTINGS_KEY] ?? [];
        $endpoint = is_string($raw['endpoint'] ?? null) && $raw['endpoint'] !== '' ? $raw['endpoint'] : self::DEFAULT_ENDPOINT;
        return [
            'enabled' => (bool) ($raw['online_geocoding'] ?? false),
            'endpoint' => $endpoint,
        ];
    }

    public function isEnabled(Tenant $tenant): bool
    {
        return self::settings($tenant)['enabled'];
    }

    /**
     * Refine locations that are missing or only placed at country level.
     *
     * @param iterable<Location> $locations
     * @return int number of locations refined
     */
    public function refine(Tenant $tenant, iterable $locations): int
    {
        $settings = self::settings($tenant);
        if (!$settings['enabled']) {
            return 0;
        }
        $done = 0;
        $calls = 0;
        foreach ($locations as $location) {
            if (!self::needsRefinement($location)) {
                continue;
            }
            if ($calls >= self::MAX_PER_RUN) {
                break;
            }
            if ($calls > 0) {
                usleep(1_000_000);
            }
            ++$calls;
            $point = $this->lookup($settings['endpoint'], $location);
            if ($point !== null) {
                $location->setLatitude($point->latitude)
                    ->setLongitude($point->longitude)
                    ->setGeoSource(LocationGeocoder::SOURCE_ONLINE);
                ++$done;
            }
        }
        return $done;
    }

    public static function needsRefinement(Location $location): bool
    {
        $hasAddress = trim((string) $location->getPostalCode()) !== '' || trim((string) $location->getCity()) !== '';
        return $hasAddress
            && in_array($location->getGeoSource(), [null, LocationGeocoder::SOURCE_COUNTRY], true);
    }

    public function lookup(string $endpoint, Location $location): ?GeoPoint
    {
        $query = array_filter([
            'postalcode' => trim((string) $location->getPostalCode()),
            'city' => trim((string) $location->getCity()),
            'countrycodes' => strtolower((string) $this->geocoder->countryCode($location->getCountry())),
            'format' => 'jsonv2',
            'limit' => '1',
        ], static fn (string $v): bool => $v !== '');

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'query' => $query,
                'headers' => ['User-Agent' => 'Little-ISMS-Helper location map', 'Accept' => 'application/json'],
                'timeout' => 5,
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Online geocoding failed', ['error' => $e->getMessage()]);
            return null;
        }
        $hit = $data[0] ?? null;
        if (!is_array($hit) || !is_numeric($hit['lat'] ?? null) || !is_numeric($hit['lon'] ?? null)) {
            return null;
        }
        return new GeoPoint(round((float) $hit['lat'], 4), round((float) $hit['lon'], 4), LocationGeocoder::SOURCE_ONLINE);
    }
}
