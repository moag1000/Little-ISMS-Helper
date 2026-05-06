<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\AlvaHint\AlvaHintService;
use App\Controller\AlvaHintController;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AlvaHintControllerTest extends TestCase
{
    private MockObject $alvaHintService;
    private MockObject $auditLogger;
    private MockObject $container;
    private MockObject $csrfTokenManager;
    private MockObject $tokenStorage;
    private AlvaHintController $controller;

    protected function setUp(): void
    {
        $this->alvaHintService = $this->createMock(AlvaHintService::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);

        $this->container->method('has')->willReturnCallback(
            static fn(string $id): bool => in_array($id, [
                'security.csrf.token_manager',
                'security.token_storage',
            ], true),
        );
        $this->container->method('get')->willReturnCallback(
            fn(string $id): object => match ($id) {
                'security.csrf.token_manager' => $this->csrfTokenManager,
                'security.token_storage' => $this->tokenStorage,
                default => throw new \LogicException("unexpected $id"),
            },
        );

        $this->controller = new AlvaHintController($this->alvaHintService, $this->auditLogger);
        $this->controller->setContainer($this->container);
    }

    #[Test]
    public function rejectsInvalidCsrf(): void
    {
        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);
        $this->loginUser();

        $request = $this->buildRequest(['hint_key' => 'asset.foo', '_token' => 'wrong']);

        $this->alvaHintService->expects($this->never())->method('dismiss');

        $response = $this->controller->dismiss($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rejectsEmptyHintKey(): void
    {
        $this->loginUser();

        $request = $this->buildRequest(['hint_key' => '', '_token' => 'whatever']);

        $response = $this->controller->dismiss($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function persistsDismissAndAuditsOnHappyPath(): void
    {
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);
        $user = $this->loginUser();

        $this->alvaHintService->expects($this->once())
            ->method('dismiss')
            ->with(
                $user,
                $user->getTenant(),
                'asset.protection_inheritance',
                'Asset',
                7,
                null,
            );

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'alva_hint.dismissed',
                'AlvaHintDismissal',
                null,
                null,
                $this->callback(function (array $context): bool {
                    return $context['hint_key'] === 'asset.protection_inheritance'
                        && $context['source'] === 'alva_hint';
                }),
                $this->stringContains('asset.protection_inheritance'),
            );

        $request = $this->buildRequest([
            'hint_key' => 'asset.protection_inheritance',
            'entity_type' => 'Asset',
            'entity_id' => '7',
            '_token' => 'good',
        ]);

        $response = $this->controller->dismiss($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function snoozeDaysProducesFutureUntilTimestamp(): void
    {
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);
        $user = $this->loginUser();

        $this->alvaHintService->expects($this->once())
            ->method('dismiss')
            ->with(
                $user,
                $user->getTenant(),
                'consent.expiring_soon',
                'Consent',
                42,
                $this->callback(static function (?\DateTimeImmutable $until): bool {
                    return $until instanceof \DateTimeImmutable
                        && $until > new \DateTimeImmutable('+29 days')
                        && $until < new \DateTimeImmutable('+31 days');
                }),
            );

        $request = $this->buildRequest([
            'hint_key' => 'consent.expiring_soon',
            'entity_type' => 'Consent',
            'entity_id' => '42',
            'snooze_days' => '30',
            '_token' => 'good',
        ]);

        $response = $this->controller->dismiss($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $params
     */
    private function buildRequest(array $params): Request
    {
        $request = new Request();
        $request->setMethod('POST');
        $request->request->replace($params);

        return $request;
    }

    private function loginUser(): User
    {
        $tenant = new Tenant();
        $user = new User();
        $user->setTenant($tenant);

        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        return $user;
    }
}
