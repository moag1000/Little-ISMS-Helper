<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Service\Location\LocationPeers;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocationPeersTest extends TestCase
{
    /** @param list<string> $gaps */
    private static function site(int $id, string $type, int $protection, ?int $rate, array $tags = [], array $gaps = [], array $checks = []): array
    {
        return [
            'id' => $id, 'name' => 'Site ' . $id, 'type' => $type, 'protection' => $protection,
            'checksRate' => $rate, 'score' => $protection * 3, 'tags' => $tags, 'gaps' => $gaps, 'checks' => $checks,
        ];
    }

    #[Test]
    public function sharedTagWinsOverTypeAndProtection(): void
    {
        $rows = [
            self::site(1, 'building', 4, 50, ['LatAm']),
            self::site(2, 'campus', 1, 80, ['LatAm']),
            self::site(3, 'office', 2, 100, ['LatAm']),
            self::site(4, 'building', 4, 90),
        ];

        $peers = LocationPeers::compare($rows);

        self::assertSame('tag', $peers[1]['basis']);
        self::assertSame('LatAm', $peers[1]['tag']);
        self::assertSame(3, $peers[1]['size']);
        self::assertSame(80, $peers[1]['medianRate']);
        self::assertSame('bottom', $peers[1]['rank']);
        self::assertSame('top', $peers[3]['rank']);
    }

    #[Test]
    public function fallsBackToTypeThenProtectionBand(): void
    {
        $rows = [
            self::site(1, 'building', 3, 60),
            self::site(2, 'building', 4, 70),
            self::site(3, 'building', 2, 80),
            self::site(4, 'office', 3, 90),
        ];

        $peers = LocationPeers::compare($rows);

        self::assertSame('type', $peers[1]['basis']);
        self::assertSame(3, $peers[1]['size']);
        self::assertSame('band', $peers[4]['basis'], 'only one office: protection band ±1 across types');
    }

    #[Test]
    public function groupsBelowThreeSitesGetNoComparison(): void
    {
        $peers = LocationPeers::compare([
            self::site(1, 'building', 1, 60),
            self::site(2, 'building', 5, 70),
        ]);

        self::assertSame([], $peers);
    }

    #[Test]
    public function namesThePeerThatPassesAnOpenCheck(): void
    {
        $rows = [
            self::site(1, 'building', 3, 50, gaps: ['camera'], checks: ['camera' => false]),
            self::site(2, 'building', 3, 90, checks: ['camera' => true]),
            self::site(3, 'building', 3, 70, checks: ['camera' => true]),
        ];

        $peers = LocationPeers::compare($rows);

        self::assertSame(['id' => 2, 'name' => 'Site 2'], $peers[1]['solvedAt']['camera']);
    }

    #[Test]
    public function closedSitesAreNeitherComparedNorPeers(): void
    {
        $closed = self::site(4, 'building', 3, 10);
        $closed['active'] = false;

        $peers = LocationPeers::compare([
            self::site(1, 'building', 3, 60),
            self::site(2, 'building', 3, 70),
            $closed,
        ]);

        self::assertSame([], $peers, 'two open sites are below the minimum group');
    }
}
