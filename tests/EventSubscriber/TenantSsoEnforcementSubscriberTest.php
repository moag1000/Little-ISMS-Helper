<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\EventSubscriber\TenantSsoEnforcementSubscriber;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Makes Tenant.ssoEnforced real: a password login by a user whose tenant has
 * ssoEnforced=true is short-circuited to that tenant's enabled SSO provider,
 * before the firewall authenticates. Anti-lockout: if the tenant has NO usable
 * enabled provider, the password login proceeds (fail-open).
 */
#[AllowMockObjectsWithoutExpectations]
final class TenantSsoEnforcementSubscriberTest extends TestCase
{
    #[Test]
    public function enforcedTenantWithProviderIsRedirectedToSso(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch(
            '/de/login',
            'POST',
            ['_username' => 'bob@acme.de'],
            tenantEnforced: true,
            tenantProviders: [$provider],
        );

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/sso/acme-entra/start', $response->getTargetUrl());
    }

    #[Test]
    public function enforcedTenantWithoutProviderFailsOpen(): void
    {
        // Anti-lockout: SSO enforced but no enabled provider → password login proceeds.
        $event = $this->dispatch(
            '/de/login',
            'POST',
            ['_username' => 'bob@acme.de'],
            tenantEnforced: true,
            tenantProviders: [],
        );

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function enforcedTenantWithSluglessProviderFailsOpen(): void
    {
        // Anti-lockout: provider exists but has no slug → cannot build a redirect.
        $provider = new IdentityProvider();
        $provider->setEnabled(true); // slug stays null

        $event = $this->dispatch(
            '/de/login',
            'POST',
            ['_username' => 'bob@acme.de'],
            tenantEnforced: true,
            tenantProviders: [$provider],
        );

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function nonEnforcedTenantFallsThroughToPassword(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch(
            '/de/login',
            'POST',
            ['_username' => 'bob@acme.de'],
            tenantEnforced: false,
            tenantProviders: [$provider],
        );

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function unknownEmailIsIgnored(): void
    {
        $event = $this->dispatch(
            '/de/login',
            'POST',
            ['_username' => 'ghost@nowhere.de'],
            tenantEnforced: true,
            tenantProviders: [],
            userExists: false,
        );

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function getLoginIsNeverIntercepted(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch(
            '/de/login',
            'GET',
            [],
            tenantEnforced: true,
            tenantProviders: [$provider],
        );

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function emptyEmailIsIgnored(): void
    {
        $event = $this->dispatch(
            '/de/login',
            'POST',
            ['_username' => ''],
            tenantEnforced: true,
            tenantProviders: [],
        );

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function nonLoginPathIsIgnored(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch(
            '/de/dashboard',
            'POST',
            ['_username' => 'bob@acme.de'],
            tenantEnforced: true,
            tenantProviders: [$provider],
        );

        self::assertNull($event->getResponse());
    }

    /**
     * @param array<string, string>   $params
     * @param list<IdentityProvider>  $tenantProviders
     */
    private function dispatch(
        string $path,
        string $method,
        array $params,
        bool $tenantEnforced,
        array $tenantProviders,
        bool $userExists = true,
    ): RequestEvent {
        $userRepository = $this->createMock(UserRepository::class);
        if ($userExists) {
            $tenant = new Tenant();
            $tenant->setSsoEnforced($tenantEnforced);
            $resolvedUser = new User();
            $resolvedUser->setTenant($tenant);
            $userRepository->method('findOneBy')->willReturn($resolvedUser);
        } else {
            $userRepository->method('findOneBy')->willReturn(null);
        }

        $idpRepository = $this->createMock(IdentityProviderRepository::class);
        $idpRepository->method('findEnabledForTenant')->willReturn($tenantProviders);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $p = []): string => '/sso/' . ($p['slug'] ?? '') . '/start',
        );

        $subscriber = new TenantSsoEnforcementSubscriber($userRepository, $idpRepository, $urlGenerator);

        $request = Request::create($path, $method, $params);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequest($event);

        return $event;
    }
}
