<?php

declare(strict_types=1);

namespace App\Service\Location;

final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $source,
    ) {
    }
}
