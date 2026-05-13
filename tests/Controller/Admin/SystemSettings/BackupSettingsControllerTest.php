<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\SystemSettings;

use App\Controller\Admin\SystemSettings\BackupSettingsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BackupSettingsControllerTest extends TestCase
{
    #[Test]
    public function classIsGrantedRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(BackupSettingsController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs);
        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    #[Test]
    public function classRouteContainsBackups(): void
    {
        $reflection = new \ReflectionClass(BackupSettingsController::class);
        $attrs = $reflection->getAttributes(Route::class);
        self::assertCount(1, $attrs);
        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        self::assertStringContainsString('admin/settings/backups', $route->getPath());
    }

    #[Test]
    public function editMethodHasCorrectRouteName(): void
    {
        $method = new \ReflectionMethod(BackupSettingsController::class, 'edit');
        $routeAttrs = $method->getAttributes(Route::class);
        self::assertNotEmpty($routeAttrs);
        /** @var Route $route */
        $route = $routeAttrs[0]->newInstance();
        self::assertSame('admin_settings_backups', $route->getName());
    }
}
