<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes for the location map: store coordinates for a tenant's locations
 * and save the map settings (Tenant.settings['location_map']).
 */
class LocationCoordinateUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationGeocoder $geocoder,
        private readonly OnlineGeocoder $onlineGeocoder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Offline for every location without a source, then — when enabled —
     * online for those placed at country level only.
     *
     * @return int number of locations placed or refined
     */
    public function storeForTenant(Tenant $tenant): int
    {
        /** @var list<Location> $locations */
        $locations = $this->em->getRepository(Location::class)->findBy(['tenant' => $tenant]);
        $placed = 0;
        foreach ($locations as $location) {
            if ($location->getGeoSource() === null && $this->geocoder->fillCoordinates($location)) {
                ++$placed;
            }
        }
        $placed += $this->onlineGeocoder->refine($tenant, $locations);
        $this->em->flush();
        return $placed;
    }

    public function saveSettings(Tenant $tenant, bool $onlineGeocoding, string $endpoint, LocationMapSettings $map): void
    {
        $oldOnline = OnlineGeocoder::settings($tenant);
        $old = ['online_geocoding' => $oldOnline['enabled'], 'endpoint' => $oldOnline['endpoint']]
            + LocationMapSettings::of($tenant)->toArray();
        $new = ['online_geocoding' => $onlineGeocoding, 'endpoint' => $endpoint] + $map->toArray();
        $settings = $tenant->getSettings() ?? [];
        $settings[OnlineGeocoder::SETTINGS_KEY] = $new;
        $tenant->setSettings($settings);
        $this->em->flush();

        $this->auditLogger->logUpdate(
            entityType: 'Tenant',
            entityId: $tenant->getId(),
            oldValues: $old,
            newValues: $new,
            description: 'Location map settings updated',
        );
    }
}
