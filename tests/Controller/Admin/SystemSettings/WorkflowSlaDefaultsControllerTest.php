<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\SystemSettings;

use App\Controller\Admin\SystemSettings\WorkflowSlaDefaultsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WorkflowSlaDefaultsControllerTest extends TestCase
{
    #[Test]
    public function classIsGrantedRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(WorkflowSlaDefaultsController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs);
        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    #[Test]
    public function classRouteContainsWorkflowSlas(): void
    {
        $reflection = new \ReflectionClass(WorkflowSlaDefaultsController::class);
        $attrs = $reflection->getAttributes(Route::class);
        self::assertCount(1, $attrs);
        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        self::assertStringContainsString('admin/settings/workflow-slas', $route->getPath());
    }

    #[Test]
    public function editMethodHasCorrectRouteName(): void
    {
        $method = new \ReflectionMethod(WorkflowSlaDefaultsController::class, 'edit');
        $routeAttrs = $method->getAttributes(Route::class);
        self::assertNotEmpty($routeAttrs);
        /** @var Route $route */
        $route = $routeAttrs[0]->newInstance();
        self::assertSame('admin_settings_workflow_slas', $route->getName());
    }

    #[Test]
    public function dataBreachDefaultMeetsGdprMinimum(): void
    {
        $reflection = new \ReflectionClass(WorkflowSlaDefaultsController::class);
        $constants = $reflection->getConstants();
        self::assertArrayHasKey('DEFAULTS', $constants);
        self::assertArrayHasKey('REGULATORY_MINIMUMS', $constants);

        $defaults = $constants['DEFAULTS'];
        $minimums = $constants['REGULATORY_MINIMUMS'];

        // GDPR Art. 33: data breach must be notified within 72h
        self::assertArrayHasKey('data_breach', $defaults);
        self::assertGreaterThanOrEqual(72, $defaults['data_breach'], 'data_breach default must be >= 72h (GDPR Art. 33)');

        self::assertArrayHasKey('data_breach', $minimums);
        self::assertSame(72, $minimums['data_breach'], 'data_breach regulatory minimum must be exactly 72h');
    }
}
