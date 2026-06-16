<?php

declare(strict_types=1);

/**
 * ComplianceFrameworkLibraryControllerTest
 *
 * Unit tests for ComplianceFrameworkLibraryController:
 * - Framework library browse view (GET)
 * - Load framework for manager (POST, CSRF-protected)
 *
 * Uses PHPUnit 13.1 with proper mocking patterns.
 */

namespace App\Tests\Controller;

use App\Controller\ComplianceFrameworkLibraryController;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class ComplianceFrameworkLibraryControllerTest extends TestCase
{
    private MockObject $complianceFrameworkLoaderService;
    private MockObject $csrfTokenManager;
    private MockObject $moduleConfigurationService;
    private ComplianceFrameworkLibraryController $controller;

    protected function setUp(): void
    {
        $this->complianceFrameworkLoaderService = $this->createMock(ComplianceFrameworkLoaderService::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->moduleConfigurationService = $this->createMock(ModuleConfigurationService::class);

        $this->controller = new ComplianceFrameworkLibraryController(
            $this->complianceFrameworkLoaderService,
            $this->csrfTokenManager,
            $this->moduleConfigurationService,
        );

        $this->setupControllerContainer();
    }

    private function setupControllerContainer(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>Test</html>');

        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/test-url');

        $flashBag = $this->createMock(\Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface::class);

        $session = $this->createMock(\Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface::class);
        $session->method('get')->willReturn([]);
        $session->method('getFlashBag')->willReturn($flashBag);
        // Stub has* check used by hasSession() / addFlash() internals
        $session->method('has')->willReturn(false);

        $request = new Request();
        $request->setSession($session);

        $requestStack = $this->createMock(\Symfony\Component\HttpFoundation\RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn($request);
        $requestStack->method('getSession')->willReturn($session);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(function ($id) use ($twig, $router, $requestStack) {
            return match ($id) {
                'twig' => $twig,
                'router' => $router,
                'request_stack' => $requestStack,
                default => null,
            };
        });

        $this->controller->setContainer($container);
    }

    #[Test]
    public function testFrameworkLibraryRendersAvailableFrameworks(): void
    {
        $this->complianceFrameworkLoaderService->method('getAvailableFrameworks')
            ->willReturn([['code' => 'ISO27001', 'name' => 'ISO 27001']]);

        $this->complianceFrameworkLoaderService->method('getFrameworkStatistics')
            ->willReturn(['total' => 1, 'loaded' => 0]);

        $this->moduleConfigurationService->method('getAllModules')->willReturn([]);
        $this->moduleConfigurationService->method('getActiveModules')->willReturn([]);

        $response = $this->controller->frameworkLibrary();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testLoadFrameworkForManagerRejectsMissingCsrfToken(): void
    {
        $this->csrfTokenManager->method('isTokenValid')
            ->with($this->isInstanceOf(CsrfToken::class))
            ->willReturn(false);

        $request = new Request();
        // No X-CSRF-Token header set

        $response = $this->controller->loadFrameworkForManager('ISO27001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testLoadFrameworkForManagerSuccessfulLoad(): void
    {
        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkLoaderService->method('loadFramework')
            ->with('ISO27001')
            ->willReturn(['success' => true, 'message' => 'Framework loaded successfully.']);

        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'valid-token');

        $response = $this->controller->loadFrameworkForManager('ISO27001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    #[Test]
    public function testLoadFrameworkForManagerHandlesLoadFailure(): void
    {
        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkLoaderService->method('loadFramework')
            ->with('UNKNOWN')
            ->willReturn(['success' => false, 'message' => 'Framework not found.']);

        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'valid-token');

        $response = $this->controller->loadFrameworkForManager('UNKNOWN', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
    }
}
