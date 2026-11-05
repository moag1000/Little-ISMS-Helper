<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\TenantOnCallExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantOnCallExtensionTest extends TestCase
{
    #[Test]
    public function picksLatestEntryUpToToday(): void
    {
        $rota = [
            ['date' => '2026-12-01', 'primary' => 'Future'],
            ['date' => '2026-10-01', 'primary' => 'Old', 'deputy' => 'D1'],
            ['date' => '2026-11-01', 'primary' => 'Current', 'deputy' => 'D2'],
        ];

        $current = (new TenantOnCallExtension())->currentOnCall($rota, new \DateTimeImmutable('2026-11-05'));

        self::assertSame('Current', $current['primary'] ?? null);
        self::assertSame('D2', $current['deputy'] ?? null);
    }

    #[Test]
    public function returnsNullWithoutUsableEntry(): void
    {
        $ext = new TenantOnCallExtension();
        $now = new \DateTimeImmutable('2026-11-05');

        self::assertNull($ext->currentOnCall(null, $now));
        self::assertNull($ext->currentOnCall([['date' => '2027-01-01', 'primary' => 'X']], $now));
        self::assertNull($ext->currentOnCall([['date' => '2026-11-01']], $now));
    }
}
