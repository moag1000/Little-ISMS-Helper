<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\LiveCountsController;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\LiveCountAggregator;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Phase 4.4 — LiveCountsController unit test.
 *
 * Verifies:
 *   1. Response shape: JSON object with my_day, activity, inbox, approvals_pending keys
 *   2. Cache headers: Cache-Control includes max-age=5 and private
 *   3. All counts are non-negative integers
 */
#[AllowMockObjectsWithoutExpectations]
final class LiveCountsControllerTest extends TestCase
{
    private MockObject $aggregator;
    private MockObject $tenantContext;
    private MockObject $container;
    private LiveCountsController $controller;

    protected function setUp(): void
    {
        $this->aggregator    = $this->createMock(LiveCountAggregator::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->controller = new LiveCountsController(
            $this->aggregator,
            $this->tenantContext,
        );

        // Build minimal container mock with security token storage
        $this->container = $this->createMock(ContainerInterface::class);

        $user  = $this->createMock(User::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $this->container->method('has')->willReturnCallback(function (string $id): bool {
            return in_array($id, ['security.token_storage', 'service_container'], true);
        });
        $this->container->method('get')->willReturnCallback(
            function (string $id) use ($tokenStorage): mixed {
                return match ($id) {
                    'security.token_storage' => $tokenStorage,
                    default => null,
                };
            }
        );

        $this->controller->setContainer($this->container);
    }

    #[Test]
    public function responseShapeContainsAllExpectedKeys(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->aggregator->method('getCounts')->willReturn([
            'my_day'            => 7,
            'activity'          => 12,
            'inbox'             => 3,
            'approvals_pending' => 2,
        ]);

        $response = $this->controller->__invoke();

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('my_day', $data);
        self::assertArrayHasKey('activity', $data);
        self::assertArrayHasKey('inbox', $data);
        self::assertArrayHasKey('approvals_pending', $data);
        self::assertSame(7, $data['my_day']);
        self::assertSame(12, $data['activity']);
        self::assertSame(3, $data['inbox']);
        self::assertSame(2, $data['approvals_pending']);
    }

    #[Test]
    public function responseCacheHeadersArePrivateMaxAgeFive(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->aggregator->method('getCounts')->willReturn([
            'my_day' => 0, 'activity' => 0, 'inbox' => 0, 'approvals_pending' => 0,
        ]);

        $response = $this->controller->__invoke();

        $cacheControl = $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', (string) $cacheControl);
        self::assertStringContainsString('max-age=5', (string) $cacheControl);
    }

    #[Test]
    public function allCountValuesAreNonNegativeIntegers(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->aggregator->method('getCounts')->willReturn([
            'my_day' => 0, 'activity' => 5, 'inbox' => 0, 'approvals_pending' => 1,
        ]);

        $response = $this->controller->__invoke();

        $data = json_decode((string) $response->getContent(), true);
        foreach ($data as $key => $value) {
            self::assertIsInt($value, "Expected integer for key '{$key}'");
            self::assertGreaterThanOrEqual(0, $value, "Expected non-negative value for key '{$key}'");
        }
    }
}
