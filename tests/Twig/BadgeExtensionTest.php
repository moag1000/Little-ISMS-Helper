<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\BadgeExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** One severity vocabulary for every pill (replaces the local colour maps in templates). */
final class BadgeExtensionTest extends TestCase
{
    #[Test]
    public function severityScaleMapsToAuroraTones(): void
    {
        $ext = new BadgeExtension();

        self::assertSame('danger', $ext->getSeverityTone('critical'));
        self::assertSame('warning', $ext->getSeverityTone('HIGH'));
        self::assertSame('primary', $ext->getSeverityTone('medium'));
        self::assertSame('success', $ext->getSeverityTone('low'));
        self::assertSame('danger', $ext->getSeverityTone(5));
    }

    #[Test]
    public function missingOrUnknownSeverityIsNeutral(): void
    {
        $ext = new BadgeExtension();

        self::assertSame('neutral', $ext->getSeverityTone(null));
        self::assertSame('neutral', $ext->getSeverityTone(''));
        self::assertSame('neutral', $ext->getSeverityTone('unknown'));
    }
}
