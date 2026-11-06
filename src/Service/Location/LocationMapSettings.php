<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Tenant;

/**
 * The three tenant settings of the location map (Tenant.settings['location_map'],
 * next to the online-geocoding keys of {@see OnlineGeocoder}):
 *
 *   - peerMode: off | anonymous | named. "anonymous" (default) compares sites
 *     with each other but never names the responsible persons of other sites,
 *     because a ranking of persons can count as performance monitoring
 *     (§ 87 Abs. 1 Nr. 6 BetrVG). "named" is for tenants that agreed on it.
 *   - precision: city | exact. "city" (default) rounds coordinates to two
 *     decimals (about 1 km) on the map and in exports, so data-centre
 *     addresses do not end up in screenshots.
 *   - disabledChecks: gap checks a tenant does not use (e.g. camera when no
 *     site has the level "secure"). The owner check always applies (A.5.2).
 */
final readonly class LocationMapSettings
{
    public const string PEER_OFF = 'off';
    public const string PEER_ANONYMOUS = 'anonymous';
    public const string PEER_NAMED = 'named';
    public const array PEER_MODES = [self::PEER_OFF, self::PEER_ANONYMOUS, self::PEER_NAMED];

    public const string PRECISION_CITY = 'city';
    public const string PRECISION_EXACT = 'exact';
    public const array PRECISIONS = [self::PRECISION_CITY, self::PRECISION_EXACT];

    /** Every check except the owner check may be switched off. */
    public const array OPTIONAL_CHECKS = ['deputy', 'access_control', 'camera', 'risks_treated', 'incidents_closed'];

    /**
     * @param list<string> $disabledChecks
     */
    public function __construct(
        public string $peerMode = self::PEER_ANONYMOUS,
        public string $precision = self::PRECISION_CITY,
        public array $disabledChecks = [],
    ) {
    }

    public static function of(?Tenant $tenant): self
    {
        $raw = ($tenant?->getSettings() ?? [])[OnlineGeocoder::SETTINGS_KEY] ?? [];

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /**
     * Unknown values fall back to the defaults instead of failing.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $peer = $raw['peer_mode'] ?? null;
        $precision = $raw['precision'] ?? null;
        $disabled = is_array($raw['disabled_checks'] ?? null) ? $raw['disabled_checks'] : [];

        return new self(
            in_array($peer, self::PEER_MODES, true) ? $peer : self::PEER_ANONYMOUS,
            in_array($precision, self::PRECISIONS, true) ? $precision : self::PRECISION_CITY,
            array_values(array_intersect(self::OPTIONAL_CHECKS, $disabled)),
        );
    }

    /** @return array{peer_mode: string, precision: string, disabled_checks: list<string>} */
    public function toArray(): array
    {
        return [
            'peer_mode' => $this->peerMode,
            'precision' => $this->precision,
            'disabled_checks' => $this->disabledChecks,
        ];
    }

    public function isCheckActive(string $check): bool
    {
        return !in_array($check, $this->disabledChecks, true);
    }

    public function comparesPeers(): bool
    {
        return $this->peerMode !== self::PEER_OFF;
    }

    public function namesOwners(): bool
    {
        return $this->peerMode === self::PEER_NAMED;
    }

    /** Coordinate for display and export, rounded unless the tenant chose "exact". */
    public function display(?float $coordinate): ?float
    {
        if ($coordinate === null || $this->precision === self::PRECISION_EXACT) {
            return $coordinate;
        }

        return round($coordinate, 2);
    }
}
