<?php

declare(strict_types=1);

namespace App\Tests\Serializer;

use App\Serializer\DefaultTimezoneDateTimeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/** #1207: a date sent with any offset is stored in the server zone, so the next read returns the same instant. */
final class DefaultTimezoneDateTimeNormalizerTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /** @return iterable<string, array{string}> */
    public static function sameInstant(): iterable
    {
        yield 'UTC' => ['2026-10-31T08:00:00Z'];
        yield 'offset +01:00' => ['2026-10-31T09:00:00+01:00'];
        yield 'offset -05:00' => ['2026-10-31T03:00:00-05:00'];
    }

    #[Test]
    #[DataProvider('sameInstant')]
    public function aSentInstantComesBackAsTheSameInstantInTheServerZone(string $sent): void
    {
        $normalizer = new DefaultTimezoneDateTimeNormalizer(new DateTimeNormalizer());

        $date = $normalizer->denormalize($sent, \DateTimeImmutable::class);

        self::assertSame('Europe/Berlin', $date->getTimezone()->getName());
        self::assertSame('2026-10-31T09:00:00+01:00', $date->format('c'));
        // The stored wall time (what the column keeps) is the server's, so reading it back shifts nothing.
        $stored = $date->format('Y-m-d H:i:s');
        self::assertSame($date->getTimestamp(), (new \DateTimeImmutable($stored))->getTimestamp());
    }

    #[Test]
    public function aMutableDateKeepsItsClassAndTakesTheServerZone(): void
    {
        $normalizer = new DefaultTimezoneDateTimeNormalizer(new DateTimeNormalizer());

        $date = $normalizer->denormalize('2026-10-31T08:00:00Z', \DateTime::class);

        self::assertInstanceOf(\DateTime::class, $date);
        self::assertSame('2026-10-31T09:00:00+01:00', $date->format('c'));
    }

    #[Test]
    public function outputIsLeftToTheStockNormalizer(): void
    {
        $normalizer = new DefaultTimezoneDateTimeNormalizer(new DateTimeNormalizer());

        self::assertSame(
            '2026-10-31T08:00:00+00:00',
            $normalizer->normalize(new \DateTimeImmutable('2026-10-31T08:00:00Z')),
        );
    }
}
