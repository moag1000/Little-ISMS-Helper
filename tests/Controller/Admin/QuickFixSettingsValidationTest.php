<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\QuickFixSettingsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ip_allowlist validation that protects operators from
 * silently locking themselves out of the Quick-Fix UI by entering a malformed
 * IP/CIDR (QuickFixGuard fails closed on an unmatchable allowlist).
 *
 * invalidIpAllowlistEntries() is a pure, dependency-free static helper, so the
 * validation contract is pinned directly without a full HTTP round-trip.
 */
class QuickFixSettingsValidationTest extends TestCase
{
    /**
     * @param list<string> $expectedInvalid
     */
    #[Test]
    #[DataProvider('allowlistCases')]
    public function itFlagsOnlyMalformedEntries(string $input, array $expectedInvalid): void
    {
        self::assertSame(
            $expectedInvalid,
            QuickFixSettingsController::invalidIpAllowlistEntries($input),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function allowlistCases(): iterable
    {
        yield 'empty is valid'            => ['', []];
        yield 'exact IPv4'                => ['10.0.0.5', []];
        yield 'IPv6 loopback'             => ['::1', []];
        yield 'IPv4 CIDR'                 => ['192.168.1.0/24', []];
        yield 'IPv6 CIDR'                 => ['fe80::/10', []];
        yield 'mixed exact + CIDR'        => ['8.8.8.8, 10.0.0.0/8, ::1', []];
        yield 'prefix out of range'       => ['192.168.1.0/99', ['192.168.1.0/99']];
        yield 'garbage entry'             => ['not-an-ip', ['not-an-ip']];
        yield 'invalid octet'             => ['300.1.2.3', ['300.1.2.3']];
        yield 'one bad among valid'       => ['10.0.0.5, bogus, 1.2.3.4/24', ['bogus']];
        yield 'missing prefix after slash' => ['10.0.0.0/', ['10.0.0.0/']];
    }
}
