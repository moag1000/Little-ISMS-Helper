<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MetricsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** /metrics is public: process gauges only, no release version and no tenant data. */
final class MetricsControllerTest extends TestCase
{
    #[Test]
    public function thePublicEndpointCarriesNoVersion(): void
    {
        $body = (string) (new MetricsController())()->getContent();

        self::assertStringContainsString('app_up 1', $body);
        self::assertStringNotContainsString('version=', $body);
        self::assertStringNotContainsString('app_build_info', $body);
    }
}
