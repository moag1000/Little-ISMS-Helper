<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Service\Location\LocationMapImage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Map picture in the PDF situation report: same projection and crop as the
 * interactive map, coordinates already cropped (dompdf ignores viewBox offsets).
 */
final class LocationMapImageTest extends TestCase
{
    private LocationMapImage $image;

    protected function setUp(): void
    {
        $this->image = new LocationMapImage(dirname(__DIR__, 3) . '/assets/data/world-map.json');
    }

    #[Test]
    public function projectionMatchesTheInteractiveMap(): void
    {
        // Same constants as location_map_controller.js: 0° lon is the centre, 84° N the top.
        [$x, $y] = LocationMapImage::project(0, 84);
        self::assertEqualsWithDelta(500.0, $x, 0.01);
        self::assertEqualsWithDelta(0.0, $y, 0.01);
        [$west] = LocationMapImage::project(-180, 0);
        self::assertEqualsWithDelta(0.0, $west, 0.01);
    }

    #[Test]
    public function viewFitsTheSitesAndStaysInsideTheMap(): void
    {
        $munich = LocationMapImage::project(11.6, 48.1);
        $newYork = LocationMapImage::project(-74.0, 40.7);
        [$x, $y, $w, $h] = LocationMapImage::fitView([$munich, $newYork]);

        self::assertLessThan($newYork[0], $x);
        self::assertGreaterThan($munich[0], $x + $w);
        self::assertGreaterThanOrEqual(0.0, $x);
        self::assertGreaterThanOrEqual(0.0, $y);
        self::assertLessThan(1000.0, $w);
        self::assertGreaterThan(0.0, $h);
    }

    #[Test]
    public function drawsOneShapePerSiteByRiskBand(): void
    {
        $svg = $this->image->svg([
            ['lat' => 48.1, 'lng' => 11.6, 'riskBand' => 'critical', 'protection' => 4],
            ['lat' => 52.5, 'lng' => 13.4, 'riskBand' => 'medium', 'protection' => 2],
            ['lat' => 50.1, 'lng' => 8.7, 'riskBand' => 'low', 'protection' => 1],
            ['lat' => null, 'lng' => null, 'riskBand' => 'high', 'protection' => 5],
        ]);

        self::assertNotNull($svg);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 700 ', $svg);
        self::assertSame(1, substr_count($svg, 'stroke-width="1.2"/>'), 'ring around the critical site only');
        self::assertSame(3, substr_count($svg, 'fill-opacity="0.85"'), 'one mark per placed site');
        self::assertSame(2, preg_match_all('/<path d="[^"]*" fill="#[0-9a-f]{6}" fill-opacity/', $svg), 'triangle and diamond as paths, circle for low');
        self::assertStringContainsString('#dc2626', $svg);
        self::assertStringContainsString('#d97706', $svg);
        self::assertStringContainsString('#059669', $svg);
        // Cropped to Europe: the Australian coast is dropped from the land path.
        self::assertLessThan(60000, strlen($svg));
    }

    #[Test]
    public function noImageWithoutPlacedSites(): void
    {
        self::assertNull($this->image->svg([['lat' => null, 'lng' => null, 'riskBand' => 'low']]));
        self::assertNull($this->image->dataUri([]));
        self::assertNull((new LocationMapImage('/nonexistent.json'))->svg([['lat' => 1.0, 'lng' => 1.0]]));
    }
}
