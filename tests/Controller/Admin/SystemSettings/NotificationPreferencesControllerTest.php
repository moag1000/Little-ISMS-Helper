<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\SystemSettings;

use App\Controller\Admin\SystemSettings\NotificationPreferencesController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reflection-based contract tests for NotificationPreferencesController.
 */
final class NotificationPreferencesControllerTest extends TestCase
{
    #[Test]
    public function classIsGrantedRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(NotificationPreferencesController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs, 'Controller must have one #[IsGranted] attribute');

        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    #[Test]
    public function classRouteContainsAdminSettingsNotifications(): void
    {
        $reflection = new \ReflectionClass(NotificationPreferencesController::class);
        $attrs = $reflection->getAttributes(Route::class);
        self::assertCount(1, $attrs, 'Controller must have one class-level #[Route]');

        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        self::assertStringContainsString('admin/settings/notifications', $route->getPath());
    }

    #[Test]
    public function editMethodExistsAndAcceptsGetPost(): void
    {
        $reflection = new \ReflectionClass(NotificationPreferencesController::class);
        self::assertTrue($reflection->hasMethod('edit'), 'edit() method must exist');

        $method = $reflection->getMethod('edit');
        $routeAttrs = $method->getAttributes(Route::class);
        self::assertNotEmpty($routeAttrs, 'edit() must have #[Route]');

        /** @var Route $route */
        $route = $routeAttrs[0]->newInstance();
        self::assertContains('GET', $route->getMethods());
        self::assertContains('POST', $route->getMethods());
        self::assertSame('admin_settings_notifications', $route->getName());
    }
}
