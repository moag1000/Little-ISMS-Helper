<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AdminReportStyleController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reflection-driven coverage for AdminReportStyleController
 * (Sprint report-style-admin).
 *
 * We can't boot the full kernel reliably here (sister-agent's
 * policy-style migration may not be applied yet on the dev DB),
 * so we verify the structural contract of the controller via
 * reflection: route URIs, security attributes, CSRF guards,
 * action names. This catches regressions in the public surface
 * without depending on schema state.
 */
final class AdminReportStyleControllerTest extends TestCase
{
    #[Test]
    public function classIsGrantedRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(AdminReportStyleController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs, 'controller must have one #[IsGranted] attribute');

        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    #[Test]
    public function classRoutePrefixCarriesLocaleAndAdminReportStyle(): void
    {
        $reflection = new \ReflectionClass(AdminReportStyleController::class);
        $attrs = $reflection->getAttributes(Route::class);
        self::assertCount(1, $attrs, 'controller must have one class-level #[Route]');

        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        self::assertStringContainsString('admin/report-style', $route->getPath());
        self::assertStringContainsString('{_locale}', $route->getPath());
    }

    #[Test]
    public function editActionExposesGetAndPost(): void
    {
        $method = new \ReflectionMethod(AdminReportStyleController::class, 'edit');
        $route = $this->extractRoute($method);

        self::assertSame('app_admin_report_style_edit', $route->getName());
        self::assertSame(['GET', 'POST'], $route->getMethods());
    }

    #[Test]
    public function previewActionIsCsrfGuardedAndPostOnly(): void
    {
        $method = new \ReflectionMethod(AdminReportStyleController::class, 'preview');
        $route = $this->extractRoute($method);
        self::assertSame('app_admin_report_style_preview', $route->getName());
        self::assertSame(['POST'], $route->getMethods());

        $csrf = $method->getAttributes(IsCsrfTokenValid::class);
        self::assertCount(1, $csrf, 'preview must be CSRF-guarded');

        /** @var IsCsrfTokenValid $instance */
        $instance = $csrf[0]->newInstance();
        // The IsCsrfTokenValid attribute exposes the token id via
        // its `tokenId` property in Symfony 7; older Symfony used
        // a different name. Reflect to find the value resiliently.
        $found = false;
        foreach ($csrf[0]->getArguments() as $arg) {
            if ($arg === 'admin_report_style_preview') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'preview CSRF token id must be admin_report_style_preview');
    }

    #[Test]
    public function resetActionIsCsrfGuardedAndPostOnly(): void
    {
        $method = new \ReflectionMethod(AdminReportStyleController::class, 'reset');
        $route = $this->extractRoute($method);
        self::assertSame('app_admin_report_style_reset', $route->getName());
        self::assertSame(['POST'], $route->getMethods());

        $csrf = $method->getAttributes(IsCsrfTokenValid::class);
        self::assertCount(1, $csrf, 'reset must be CSRF-guarded');

        $found = false;
        foreach ($csrf[0]->getArguments() as $arg) {
            if ($arg === 'admin_report_style_reset') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'reset CSRF token id must be admin_report_style_reset');
    }

    private function extractRoute(\ReflectionMethod $method): Route
    {
        $attrs = $method->getAttributes(Route::class);
        self::assertCount(1, $attrs, sprintf('method %s must have one #[Route]', $method->getName()));
        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        return $route;
    }
}
