<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\SystemSettings;

use App\Controller\Admin\SystemSettings\DataRetentionController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DataRetentionControllerTest extends TestCase
{
    #[Test]
    public function classIsGrantedRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(DataRetentionController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs);
        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    #[Test]
    public function classRouteContainsDataRetention(): void
    {
        $reflection = new \ReflectionClass(DataRetentionController::class);
        $attrs = $reflection->getAttributes(Route::class);
        self::assertCount(1, $attrs);
        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        self::assertStringContainsString('admin/settings/data-retention', $route->getPath());
    }

    #[Test]
    public function editMethodHasCorrectRouteName(): void
    {
        $method = new \ReflectionMethod(DataRetentionController::class, 'edit');
        $routeAttrs = $method->getAttributes(Route::class);
        self::assertNotEmpty($routeAttrs);
        /** @var Route $route */
        $route = $routeAttrs[0]->newInstance();
        self::assertSame('admin_settings_data_retention', $route->getName());
    }

    #[Test]
    public function allSevenEntityTypesAreHandled(): void
    {
        // Verify the controller handles all 7 entity types via reflection on DEFAULTS constant
        $reflection = new \ReflectionClass(DataRetentionController::class);
        $constants = $reflection->getConstants();
        self::assertArrayHasKey('DEFAULTS', $constants);
        self::assertCount(7, $constants['DEFAULTS'], 'DataRetentionController must define defaults for 7 entity types');
    }
}
