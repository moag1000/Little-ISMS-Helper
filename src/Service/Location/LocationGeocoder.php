<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;

/**
 * Offline geocoding for the location map.
 *
 * Resolution order, most precise first:
 *   1. postal code + country from the bundled DACH table (GeoNames, CC BY 4.0)
 *   2. city name + country (DACH table, then the world city table)
 *   3. country centroid (median of the country's cities)
 *
 * Nothing leaves the server here. The optional online lookup lives in
 * {@see OnlineGeocoder} and is only used when an admin enables it.
 */
class LocationGeocoder
{
    public const string SOURCE_POSTAL = 'postal';
    public const string SOURCE_CITY = 'city';
    public const string SOURCE_COUNTRY = 'country';
    public const string SOURCE_ONLINE = 'online';
    public const string SOURCE_MANUAL = 'manual';

    private const array DACH = ['DE', 'AT', 'CH'];

    /** German (or common) city names that differ from the GeoNames name; checked first. */
    private const array EXONYMS = [
        'mailand' => 'milan', 'rom' => 'rome', 'neapel' => 'naples', 'florenz' => 'florence', 'venedig' => 'venice',
        'prag' => 'prague', 'warschau' => 'warsaw', 'krakau' => 'krakow', 'breslau' => 'wroclaw',
        'danzig' => 'gdansk', 'posen' => 'poznan', 'pressburg' => 'bratislava', 'bruessel' => 'brussels',
        'kopenhagen' => 'copenhagen', 'lissabon' => 'lisbon', 'athen' => 'athens', 'bukarest' => 'bucharest',
        'belgrad' => 'belgrade', 'moskau' => 'moscow', 'kiew' => 'kyiv', 'peking' => 'beijing',
        'neu delhi' => 'new delhi', 'tokio' => 'tokyo', 'kairo' => 'cairo', 'singapur' => 'singapore',
        'bangalore' => 'bengaluru', 'bombay' => 'mumbai', 'genf' => 'geneve', 'den haag' => 'the hague',
        'mexiko stadt' => 'mexico city', 'queretaro' => 'santiago de queretaro', 'new york' => 'new york city',
    ];

    /** @var array<string, array<string, list<array{string, float, float}>>> country => postal => [place, lat, lng] */
    private array $postal = [];

    /** @var array<string, array<string, array{float, float}>> country => normalised name => [lat, lng] */
    private array $cities = [];

    /** @var array<string, string>|null lower-case name / alpha-3 => alpha-2 */
    private ?array $countryIndex = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/geo')]
        private readonly string $dataDir,
    ) {
    }

    /**
     * Fill the coordinates of a location from its address. Manually set
     * coordinates are never overwritten.
     *
     * @return bool true when the coordinates changed
     */
    public function fillCoordinates(Location $location): bool
    {
        if ($location->getGeoSource() === self::SOURCE_MANUAL) {
            return false;
        }
        $point = $this->locate($location->getPostalCode(), $location->getCity(), $location->getCountry());
        if ($point === null) {
            $changed = $location->hasCoordinates();
            $location->setLatitude(null)->setLongitude(null)->setGeoSource(null);
            return $changed;
        }
        if ($location->getLatitude() === $point->latitude
            && $location->getLongitude() === $point->longitude
            && $location->getGeoSource() === $point->source) {
            return false;
        }
        $location->setLatitude($point->latitude)
            ->setLongitude($point->longitude)
            ->setGeoSource($point->source);
        return true;
    }

    public function locate(?string $postalCode, ?string $city, ?string $country): ?GeoPoint
    {
        $code = $this->countryCode($country);
        $postalCode = $postalCode !== null ? strtoupper(trim($postalCode)) : '';
        $cityKey = self::normalise($city);

        $countryKnown = $code !== null;
        $candidates = $countryKnown ? [$code] : ($postalCode !== '' || $cityKey !== '' ? self::DACH : []);

        foreach ($candidates as $cc) {
            if ($postalCode !== '' && in_array($cc, self::DACH, true)) {
                $hit = $this->postalHit($cc, $postalCode, $cityKey, $countryKnown);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
        if ($cityKey !== '') {
            foreach ($candidates as $cc) {
                $hit = $this->cityHit($cc, $cityKey);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
        if ($code !== null) {
            return $this->countryCentroid($code);
        }
        return null;
    }

    /**
     * ISO 3166-1 alpha-2 for a free-text country ("Deutschland", "Germany",
     * "DE", "DEU", "Schweiz"), or null when it cannot be recognised.
     */
    public function countryCode(?string $country): ?string
    {
        $key = self::normalise($country);
        if ($key === '') {
            return null;
        }
        $upper = strtoupper($key);
        if (strlen($upper) === 2 && Countries::exists($upper)) {
            return $upper;
        }
        if (strlen($upper) === 3 && Countries::alpha3CodeExists($upper)) {
            return Countries::getAlpha2Code($upper);
        }
        return $this->countryIndex()[$key] ?? null;
    }

    private function postalHit(string $cc, string $postalCode, string $cityKey, bool $countryKnown): ?GeoPoint
    {
        $rows = $this->postalTable($cc)[$postalCode] ?? null;
        if ($rows === null) {
            return null;
        }
        if ($cityKey !== '') {
            foreach ($rows as [$place, $lat, $lng]) {
                if (self::normalise($place) === $cityKey || str_starts_with(self::normalise($place), $cityKey)) {
                    return new GeoPoint($lat, $lng, self::SOURCE_POSTAL);
                }
            }
            if (!$countryKnown) {
                // Without a country a bare 4-digit code is ambiguous (AT and CH).
                return null;
            }
        } elseif (!$countryKnown) {
            return null;
        }
        $lat = array_sum(array_column($rows, 1)) / count($rows);
        $lng = array_sum(array_column($rows, 2)) / count($rows);
        return new GeoPoint(round($lat, 3), round($lng, 3), self::SOURCE_POSTAL);
    }

    private function cityHit(string $cc, string $cityKey): ?GeoPoint
    {
        $table = $this->cityTable($cc);
        $hit = $table[self::EXONYMS[$cityKey] ?? $cityKey] ?? $table[$cityKey] ?? null;
        return $hit !== null ? new GeoPoint($hit[0], $hit[1], self::SOURCE_CITY) : null;
    }

    private function countryCentroid(string $cc): ?GeoPoint
    {
        $table = $this->cityTable($cc);
        if ($table === []) {
            return null;
        }
        $lats = array_column($table, 0);
        $lngs = array_column($table, 1);
        sort($lats);
        sort($lngs);
        $mid = intdiv(count($lats), 2);
        return new GeoPoint(round($lats[$mid], 2), round($lngs[$mid], 2), self::SOURCE_COUNTRY);
    }

    /** @return array<string, list<array{string, float, float}>> */
    private function postalTable(string $cc): array
    {
        if (!isset($this->postal[$cc])) {
            $this->postal[$cc] = [];
            foreach ($this->rows('postal_dach.csv.gz', $cc) as $row) {
                $this->postal[$cc][$row[1]][] = [$row[2], (float) $row[3], (float) $row[4]];
            }
        }
        return $this->postal[$cc];
    }

    /** @return array<string, array{float, float}> */
    private function cityTable(string $cc): array
    {
        if (!isset($this->cities[$cc])) {
            $table = [];
            if (in_array($cc, self::DACH, true)) {
                foreach ($this->postalTable($cc) as $rows) {
                    foreach ($rows as [$place, $lat, $lng]) {
                        $table[self::normalise($place)] ??= [$lat, $lng];
                    }
                }
            } else {
                foreach ($this->rows('cities.csv.gz', $cc) as $row) {
                    $table[self::normalise($row[1])] ??= [(float) $row[2], (float) $row[3]];
                }
            }
            $this->cities[$cc] = $table;
        }
        return $this->cities[$cc];
    }

    /**
     * Stream the rows of one country from a gzip CSV sorted by country.
     *
     * @return \Generator<int, list<string>>
     */
    private function rows(string $file, string $cc): \Generator
    {
        $handle = @gzopen($this->dataDir . '/' . $file, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            gzgets($handle); // header
            $seen = false;
            while (($line = gzgets($handle)) !== false) {
                if (!str_starts_with($line, $cc . ',')) {
                    if ($seen) {
                        break;
                    }
                    continue;
                }
                $seen = true;
                $row = str_getcsv(rtrim($line, "\r\n"), ',', '"', '');
                yield $row;
            }
        } finally {
            gzclose($handle);
        }
    }

    /** @return array<string, string> */
    private function countryIndex(): array
    {
        if ($this->countryIndex === null) {
            $index = [];
            foreach (['de', 'en', 'fr', 'it'] as $locale) {
                foreach (Countries::getNames($locale) as $alpha2 => $name) {
                    $index[self::normalise($name)] = $alpha2;
                }
            }
            $index += [
                'usa' => 'US', 'vereinigte staaten' => 'US', 'uk' => 'GB', 'england' => 'GB',
                'grossbritannien' => 'GB', 'great britain' => 'GB', 'holland' => 'NL', 'tschechei' => 'CZ',
            ];
            $this->countryIndex = $index;
        }
        return $this->countryIndex;
    }

    private static function normalise(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value) : false;
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim($value);
    }
}
