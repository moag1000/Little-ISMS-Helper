<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Location;
use App\Entity\Risk;

/**
 * `?location=<id>` list filter shared by the risk, asset and incident lists
 * (drill-down from the location map). A record belongs to a site when its
 * location, or the physical location of its asset, lies in that site's tree.
 *
 * Works on lists that are already tenant-scoped, so it needs no queries.
 */
final class LocationScope
{
    /**
     * @template T of Risk|Asset|Incident
     * @param iterable<T> $records
     * @return list<T>
     */
    public static function filter(iterable $records, int $siteId): array
    {
        $records = is_array($records) ? $records : iterator_to_array($records, false);
        if ($siteId <= 0) {
            return array_values($records);
        }
        return array_values(array_filter($records, static fn (object $record): bool => self::belongs($record, $siteId)));
    }

    public static function siteId(?Location $location): ?int
    {
        $guard = 0;
        while ($location?->getParentLocation() !== null && $guard++ < 50) {
            $location = $location->getParentLocation();
        }
        return $location?->getId();
    }

    private static function belongs(object $record, int $siteId): bool
    {
        return match (true) {
            $record instanceof Asset => self::siteId($record->getPhysicalLocation()) === $siteId,
            $record instanceof Risk => self::siteId($record->getLocation()) === $siteId
                || self::siteId($record->getAsset()?->getPhysicalLocation()) === $siteId,
            $record instanceof Incident => $record->getAffectedAssets()->exists(
                static fn (int $key, Asset $a): bool => self::siteId($a->getPhysicalLocation()) === $siteId,
            ),
            default => false,
        };
    }
}
