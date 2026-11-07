<?php

declare(strict_types=1);

namespace App\Privacy;

use Symfony\Component\Yaml\Yaml;

/**
 * GDPR transfer zone of a country (Art. 44–49), read from the versioned list
 * in config/gdpr_transfer_zones.yaml:
 *
 *   - eea       EU or EEA: no transfer in the sense of Chapter V
 *   - adequacy  Art. 45 adequacy decision
 *   - dpf       USA: adequate only for recipients certified under the
 *               EU-US Data Privacy Framework, otherwise Art. 46 safeguards
 *   - third     every other country: Art. 46 safeguards or Art. 49 derogation
 *   - unknown   no or an unreadable country code
 *
 * Static on purpose: the list is code-level reference data like
 * RiskMatrixThresholds, and callers include value objects and traits.
 */
final class GdprTransferZones
{
    public const string EEA = 'eea';
    public const string ADEQUACY = 'adequacy';
    public const string DPF = 'dpf';
    public const string THIRD = 'third';
    public const string UNKNOWN = 'unknown';

    /** Zones from least to most demanding. */
    public const array ORDER = [self::EEA, self::ADEQUACY, self::DPF, self::THIRD, self::UNKNOWN];

    /** Safeguards that make a transfer to a third country lawful (Art. 46, 49). */
    public const array SAFEGUARDS = ['scc', 'bcr', 'certification', 'derogation', 'adequacy_decision'];

    private const string FILE = __DIR__ . '/../../config/gdpr_transfer_zones.yaml';

    /** @var array{as_of: string, zone: array<string, string>, eu: list<string>}|null */
    private static ?array $data = null;

    public static function zoneOf(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return self::UNKNOWN;
        }

        return self::data()['zone'][$code] ?? self::THIRD;
    }

    public static function isEea(?string $country): bool
    {
        return self::zoneOf($country) === self::EEA;
    }

    public static function isEu(?string $country): bool
    {
        return in_array(strtoupper(trim((string) $country)), self::data()['eu'], true);
    }

    /** Date the list was last checked against the Commission's page. */
    public static function asOf(): string
    {
        return self::data()['as_of'];
    }

    /**
     * Country code => zone for every country that is not a plain third
     * country (the map colours all others as "third").
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::data()['zone'];
    }

    /** The more demanding of two zones. */
    public static function worse(string $a, string $b): string
    {
        return array_search($a, self::ORDER, true) >= array_search($b, self::ORDER, true) ? $a : $b;
    }

    /** @return array{as_of: string, zone: array<string, string>, eu: list<string>} */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        /** @var array{as_of?: string, eu?: list<string>, eea_extra?: list<string>, adequacy?: list<string>, dpf?: list<string>} $raw */
        $raw = Yaml::parseFile(self::FILE);
        $zone = [];
        foreach (['dpf' => self::DPF, 'adequacy' => self::ADEQUACY, 'eea_extra' => self::EEA, 'eu' => self::EEA] as $key => $value) {
            foreach ($raw[$key] ?? [] as $code) {
                $zone[strtoupper((string) $code)] = $value;
            }
        }

        return self::$data = [
            'as_of' => (string) ($raw['as_of'] ?? ''),
            'zone' => $zone,
            'eu' => array_map('strtoupper', $raw['eu'] ?? []),
        ];
    }
}
