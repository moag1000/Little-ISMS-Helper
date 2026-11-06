<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Location;
use App\Entity\Risk;
use App\Service\Location\LocationScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocationScopeTest extends TestCase
{
    #[Test]
    public function recordsInTheSiteTreeAreKept(): void
    {
        $site = $this->location(1);
        $room = $this->location(2)->setParentLocation($site);
        $other = $this->location(3);

        $assetInRoom = (new Asset())->setPhysicalLocation($room);
        $assetElsewhere = (new Asset())->setPhysicalLocation($other);

        $riskViaLocation = (new Risk())->setLocation($room);
        $riskViaAsset = (new Risk())->setAsset($assetInRoom);
        $riskElsewhere = (new Risk())->setLocation($other);

        $incident = new Incident();
        $incident->addAffectedAsset($assetInRoom);
        $incidentElsewhere = new Incident();
        $incidentElsewhere->addAffectedAsset($assetElsewhere);

        self::assertSame([$assetInRoom], LocationScope::filter([$assetInRoom, $assetElsewhere], 1));
        self::assertSame([$riskViaLocation, $riskViaAsset], LocationScope::filter([$riskViaLocation, $riskViaAsset, $riskElsewhere], 1));
        self::assertSame([$incident], LocationScope::filter([$incident, $incidentElsewhere], 1));
    }

    #[Test]
    public function noFilterKeepsEverything(): void
    {
        $risks = [new Risk(), new Risk()];

        self::assertSame($risks, LocationScope::filter($risks, 0));
    }

    #[Test]
    public function siteIdIsTheTopLevelLocation(): void
    {
        $site = $this->location(7);
        $floor = $this->location(8)->setParentLocation($site);
        $room = $this->location(9)->setParentLocation($floor);

        self::assertSame(7, LocationScope::siteId($room));
        self::assertNull(LocationScope::siteId(null));
    }

    private function location(int $id): Location
    {
        $location = new Location();
        $ref = new \ReflectionProperty(Location::class, 'id');
        $ref->setValue($location, $id);
        return $location;
    }
}
