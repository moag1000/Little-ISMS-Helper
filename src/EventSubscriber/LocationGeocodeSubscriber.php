<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Location;
use App\Service\Location\LocationGeocoder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps Location coordinates in step with the address, offline only.
 *
 * - Coordinates typed in by hand (form or API) are marked "manual" and kept.
 * - Clearing both coordinates hands the location back to the geocoder.
 * - A changed postal code, city or country re-geocodes, unless manual.
 *
 * Runs in onFlush so form, API, import and restore all go through it.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class LocationGeocodeSubscriber
{
    private const array ADDRESS_FIELDS = ['postalCode', 'city', 'country'];

    public function __construct(private readonly LocationGeocoder $geocoder)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = null;

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Location) {
                continue;
            }
            if ($entity->hasCoordinates()) {
                $entity->setGeoSource($entity->getGeoSource() ?? LocationGeocoder::SOURCE_MANUAL);
            } else {
                $this->geocoder->fillCoordinates($entity);
            }
            $meta ??= $em->getClassMetadata(Location::class);
            $uow->recomputeSingleEntityChangeSet($meta, $entity);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Location) {
                continue;
            }
            $changes = $uow->getEntityChangeSet($entity);
            $coordsTouched = isset($changes['latitude']) || isset($changes['longitude']);
            $addressTouched = array_intersect_key($changes, array_flip(self::ADDRESS_FIELDS)) !== [];
            $sourceTouched = isset($changes['geoSource']);

            if ($coordsTouched && !$sourceTouched) {
                if ($entity->hasCoordinates()) {
                    $entity->setGeoSource(LocationGeocoder::SOURCE_MANUAL);
                } else {
                    $entity->setGeoSource(null);
                    $this->geocoder->fillCoordinates($entity);
                }
            } elseif ($addressTouched && !$sourceTouched) {
                $this->geocoder->fillCoordinates($entity);
            } else {
                continue;
            }
            $meta ??= $em->getClassMetadata(Location::class);
            $uow->recomputeSingleEntityChangeSet($meta, $entity);
        }
    }
}
