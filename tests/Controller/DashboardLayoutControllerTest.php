<?php

namespace App\Tests\Controller;

use App\Controller\DashboardLayoutController;
use App\Entity\DashboardLayout;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DashboardLayoutRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class DashboardLayoutControllerTest extends TestCase
{
    private MockObject $dashboardLayoutRepository;
    private MockObject $tenantContext;
    private MockObject $container;
    private MockObject $tokenStorage;
    private DashboardLayoutController $controller;

    protected function setUp(): void
    {
        $this->dashboardLayoutRepository = $this->createMock(DashboardLayoutRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);

        // Configure container
        $this->container->method('has')->willReturnCallback(function ($id) {
            return $id === 'security.token_storage';
        });

        $this->container->method('get')->willReturnCallback(function ($id) {
            if ($id === 'security.token_storage') {
                return $this->tokenStorage;
            }
            return null;
        });

        $this->controller = new DashboardLayoutController(
            $this->dashboardLayoutRepository,
            $this->tenantContext
        );
        $this->controller->setContainer($this->container);
    }

    public function testGetLayoutReturnsUnauthorizedWhenUserNotFound(): void
    {
        $this->mockAuthenticatedUser(null);

        $response = $this->controller->getLayout();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('User or tenant not found', $data['error']);
    }

    public function testGetLayoutReturnsUnauthorizedWhenTenantNotFound(): void
    {
        $user = $this->createMock(User::class);
        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $response = $this->controller->getLayout();

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testGetLayoutReturnsLayoutSuccessfully(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);
        $updatedAt = new DateTimeImmutable('2025-11-28 12:00:00');

        $dashboardLayout = $this->createConfiguredMock(DashboardLayout::class, [
            'getLayoutConfig' => [
                'widgets' => [
                    'stats-cards' => ['visible' => true, 'order' => 0],
                    'risk-chart' => ['visible' => true, 'order' => 1],
                ],
                'layout' => 'grid-3-col',
            ],
            'getUpdatedAt' => $updatedAt,
        ]);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('findOrCreateForUser')
            ->with($user, $tenant)
            ->willReturn($dashboardLayout);

        $response = $this->controller->getLayout();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('layout', $data);
        $this->assertArrayHasKey('widgets', $data['layout']);
        $this->assertArrayHasKey('updated_at', $data);
        $this->assertEquals('2025-11-28T12:00:00+00:00', $data['updated_at']);
    }

    public function testSaveLayoutReturnsUnauthorizedWhenUserNotFound(): void
    {
        $this->mockAuthenticatedUser(null);

        $request = new Request([], [], [], [], [], [], json_encode(['test' => 'data']));
        $response = $this->controller->saveLayout($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSaveLayoutReturnsUnauthorizedWhenTenantNotFound(): void
    {
        $user = $this->createMock(User::class);
        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $request = new Request([], [], [], [], [], [], json_encode(['test' => 'data']));
        $response = $this->controller->saveLayout($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSaveLayoutReturnsBadRequestForInvalidJson(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $request = new Request([], [], [], [], [], [], 'invalid json {');
        $response = $this->controller->saveLayout($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid JSON data', $data['error']);
    }

    public function testSaveLayoutReturnsBadRequestForNonArrayData(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $request = new Request([], [], [], [], [], [], json_encode('string instead of array'));
        $response = $this->controller->saveLayout($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSaveLayoutSuccessfully(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);
        $updatedAt = new DateTimeImmutable('2025-11-28 13:00:00');

        $layoutConfig = [
            'widgets' => [
                'stats-cards' => ['visible' => true, 'order' => 0],
                'risk-chart' => ['visible' => false, 'order' => 1],
            ],
            'layout' => 'grid-2-col',
        ];

        $dashboardLayout = $this->createMock(DashboardLayout::class);
        $dashboardLayout->method('getUpdatedAt')->willReturn($updatedAt);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('findOrCreateForUser')
            ->with($user, $tenant)
            ->willReturn($dashboardLayout);

        $dashboardLayout
            ->expects($this->once())
            ->method('setLayoutConfig')
            ->with($layoutConfig);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('saveLayout')
            ->with($dashboardLayout);

        $request = new Request([], [], [], [], [], [], json_encode($layoutConfig));
        $response = $this->controller->saveLayout($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('saved successfully', $data['message']);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function testResetLayoutReturnsUnauthorizedWhenUserNotFound(): void
    {
        $this->mockAuthenticatedUser(null);

        $response = $this->controller->resetLayout();

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testResetLayoutReturnsUnauthorizedWhenTenantNotFound(): void
    {
        $user = $this->createMock(User::class);
        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $response = $this->controller->resetLayout();

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testResetLayoutSuccessfully(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);

        $defaultConfig = [
            'widgets' => [
                'stats-cards' => ['visible' => true, 'order' => 0, 'size' => 'default'],
            ],
            'layout' => 'grid-3-col',
        ];

        $dashboardLayout = $this->createConfiguredMock(DashboardLayout::class, [
            'getLayoutConfig' => $defaultConfig,
        ]);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('resetToDefaults')
            ->with($user, $tenant)
            ->willReturn($dashboardLayout);

        $response = $this->controller->resetLayout();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('reset to defaults', $data['message']);
        $this->assertArrayHasKey('layout', $data);
        $this->assertEquals($defaultConfig, $data['layout']);
    }

    public function testUpdateWidgetReturnsUnauthorizedWhenUserNotFound(): void
    {
        $this->mockAuthenticatedUser(null);

        $request = new Request([], [], [], [], [], [], json_encode(['visible' => false]));
        $response = $this->controller->updateWidget('stats-cards', $request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testUpdateWidgetReturnsUnauthorizedWhenTenantNotFound(): void
    {
        $user = $this->createMock(User::class);
        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $request = new Request([], [], [], [], [], [], json_encode(['visible' => false]));
        $response = $this->controller->updateWidget('stats-cards', $request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testUpdateWidgetReturnsBadRequestForInvalidJson(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $request = new Request([], [], [], [], [], [], 'invalid json');
        $response = $this->controller->updateWidget('stats-cards', $request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid JSON data', $data['error']);
    }

    public function testUpdateWidgetReturnsBadRequestForNonArrayData(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $request = new Request([], [], [], [], [], [], json_encode('not an array'));
        $response = $this->controller->updateWidget('stats-cards', $request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testUpdateWidgetSuccessfully(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);
        $widgetId = 'risk-chart';
        $widgetConfig = ['visible' => false, 'order' => 5];

        $dashboardLayout = $this->createMock(DashboardLayout::class);
        $dashboardLayout
            ->method('getWidgetConfig')
            ->with($widgetId)
            ->willReturn(['visible' => false, 'order' => 5, 'size' => 'large']);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('findOrCreateForUser')
            ->with($user, $tenant)
            ->willReturn($dashboardLayout);

        $dashboardLayout
            ->expects($this->once())
            ->method('updateWidgetConfig')
            ->with($widgetId, $widgetConfig);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('saveLayout')
            ->with($dashboardLayout);

        $request = new Request([], [], [], [], [], [], json_encode($widgetConfig));
        $response = $this->controller->updateWidget($widgetId, $request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('Widget configuration updated', $data['message']);
        $this->assertArrayHasKey('widget', $data);
    }

    public function testUpdateWidgetHandlesNonExistentWidget(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);
        $widgetId = 'non-existent-widget';

        $dashboardLayout = $this->createMock(DashboardLayout::class);
        $dashboardLayout
            ->method('getWidgetConfig')
            ->with($widgetId)
            ->willReturn(null);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->method('findOrCreateForUser')
            ->willReturn($dashboardLayout);

        $dashboardLayout
            ->expects($this->once())
            ->method('updateWidgetConfig')
            ->with($widgetId, ['visible' => true]);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('saveLayout');

        $request = new Request([], [], [], [], [], [], json_encode(['visible' => true]));
        $response = $this->controller->updateWidget($widgetId, $request);

        // Should still succeed - creating new widget config
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testGetLayoutHandlesEmptyLayoutConfig(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);
        $updatedAt = new DateTimeImmutable();

        $dashboardLayout = $this->createConfiguredMock(DashboardLayout::class, [
            'getLayoutConfig' => [],
            'getUpdatedAt' => $updatedAt,
        ]);

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->method('findOrCreateForUser')
            ->willReturn($dashboardLayout);

        $response = $this->controller->getLayout();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['layout']);
        $this->assertEmpty($data['layout']);
    }

    public function testSaveLayoutPreservesComplexNestedStructure(): void
    {
        $user = $this->createMock(User::class);
        $tenant = $this->createMock(Tenant::class);

        $complexConfig = [
            'widgets' => [
                'stats-cards' => [
                    'visible' => true,
                    'order' => 0,
                    'size' => 'default',
                    'config' => [
                        'showTotals' => true,
                        'metrics' => ['risks', 'assets', 'controls'],
                    ],
                ],
                'custom-widget' => [
                    'visible' => false,
                    'order' => 10,
                    'customData' => ['nested' => ['deeply' => ['value' => 123]]],
                ],
            ],
            'layout' => 'grid-4-col',
            'theme' => 'dark',
            'preferences' => [
                'autoRefresh' => true,
                'refreshInterval' => 60,
            ],
        ];

        $dashboardLayout = $this->createMock(DashboardLayout::class);
        $dashboardLayout->method('getUpdatedAt')->willReturn(new DateTimeImmutable());

        $this->mockAuthenticatedUser($user);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->dashboardLayoutRepository
            ->method('findOrCreateForUser')
            ->willReturn($dashboardLayout);

        $dashboardLayout
            ->expects($this->once())
            ->method('setLayoutConfig')
            ->with($complexConfig);

        $this->dashboardLayoutRepository
            ->expects($this->once())
            ->method('saveLayout');

        $request = new Request([], [], [], [], [], [], json_encode($complexConfig));
        $response = $this->controller->saveLayout($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Helper method to mock authenticated user
     */
    private function mockAuthenticatedUser(?User $user): void
    {
        if ($user === null) {
            $this->tokenStorage->method('getToken')->willReturn(null);
        } else {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $this->tokenStorage->method('getToken')->willReturn($token);
        }
    }
}
