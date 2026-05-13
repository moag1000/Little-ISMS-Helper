<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\SystemSettings;

use App\Controller\Admin\SystemSettings\ApiRateLimitsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApiRateLimitsControllerTest extends TestCase
{
    #[Test]
    public function classIsGrantedRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(ApiRateLimitsController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs);
        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    #[Test]
    public function classRouteContainsApiRateLimits(): void
    {
        $reflection = new \ReflectionClass(ApiRateLimitsController::class);
        $attrs = $reflection->getAttributes(Route::class);
        self::assertCount(1, $attrs);
        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        self::assertStringContainsString('admin/settings/api-rate-limits', $route->getPath());
    }

    #[Test]
    public function editMethodHasCorrectRouteName(): void
    {
        $method = new \ReflectionMethod(ApiRateLimitsController::class, 'edit');
        $routeAttrs = $method->getAttributes(Route::class);
        self::assertNotEmpty($routeAttrs);
        /** @var Route $route */
        $route = $routeAttrs[0]->newInstance();
        self::assertSame('admin_settings_api_rate_limits', $route->getName());
    }
}
