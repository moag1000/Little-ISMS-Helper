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
    public function unlinkedUserFailsOpenWhenProviderDoesNotLinkByEmail(): void
    {
        // #1059 anti-lockout: the provider would refuse this not-yet-linked
        // account, so the password login must stay available.
        $provider = (new IdentityProvider())->setSlug('acme-entra')->setEnabled(true)->setAllowEmailLinking(false);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$provider], userLinked: false);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function unlinkedUserIsRedirectedWhenProviderLinksByEmail(): void
    {
        $provider = (new IdentityProvider())->setSlug('acme-entra')->setEnabled(true)->setAllowEmailLinking(true);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$provider], userLinked: false);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    #[Test]
    public function linkedUserIsRedirectedEvenWhenProviderDoesNotLinkByEmail(): void
    {
        $provider = (new IdentityProvider())->setSlug('acme-entra')->setEnabled(true)->setAllowEmailLinking(false);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$provider], userLinked: true);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    #[Test]
    public function userLinkedToAnotherEnabledProviderIsRedirectedThere(): void
    {
        // #1059 I-3: a binding to another provider must not turn enforcement
        // into a password login.
        $enforced = (new IdentityProvider())->setSlug('primary')->setEnabled(true)->setAllowEmailLinking(false);
        $other = (new IdentityProvider())->setSlug('legacy-idp')->setEnabled(true);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$enforced], userLinked: false, boundTo: $other);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/sso/legacy-idp/start', $response->getTargetUrl());
    }

    #[Test]
    public function tenantOwnedProviderCannotSwitchOffOperatorLevelEnforcement(): void
    {
        // The organisation is enforced through an operator (global) provider; a
        // tenant admin adds an own provider and users sign in through it once.
        // Those users must end at an SSO provider, never at the password login.
        $operator = (new IdentityProvider())->setSlug('operator-entra')->setEnabled(true)->setAllowEmailLinking(false);
        $tenantOwn = (new IdentityProvider())->setSlug('tenant-own')->setEnabled(true)->setAllowEmailLinking(true);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$operator], userLinked: false, boundTo: $tenantOwn,
            globalProviders: true);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response, 'password login must not be reachable');
        self::assertSame('/sso/tenant-own/start', $response->getTargetUrl());
    }

    #[Test]
    public function userLinkedToDisabledProviderIsRedirectedToEnforcedProvider(): void
    {
        // #1059 I-4: a binding to a disabled provider counts as no binding —
        // the enforced provider may replace it, even with its linking off.
        $enforced = (new IdentityProvider())->setSlug('primary')->setEnabled(true)->setAllowEmailLinking(false);
        $disabled = (new IdentityProvider())->setSlug('legacy-idp')->setEnabled(false);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$enforced], userLinked: false, boundTo: $disabled);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/sso/primary/start', $response->getTargetUrl());
    }

    #[Test]
    public function tenantAdminDisablingOwnProviderSendsUsersToOperatorProvider(): void
    {
        // Users signed in through the organisation's own provider; its admin
        // switches it off. They end at the operator's provider, never password.
        $operator = (new IdentityProvider())->setSlug('operator-entra')->setEnabled(true)->setAllowEmailLinking(false);
        $tenantOwn = (new IdentityProvider())->setSlug('tenant-own')->setEnabled(false)->setAllowEmailLinking(true);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$operator], userLinked: false, boundTo: $tenantOwn,
            globalProviders: true);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response, 'password login must not be reachable');
        self::assertSame('/sso/operator-entra/start', $response->getTargetUrl());
    }

    #[Test]
    public function userMovedToAnotherOrganisationIsSentToOperatorProvider(): void
    {
        // Bound to the OLD organisation's provider (enabled, but no longer in
        // the user's tree) → counts as no binding.
        $operator = (new IdentityProvider())->setSlug('operator-entra')->setEnabled(true)->setAllowEmailLinking(false);
        $oldOrg = (new Tenant())->setName('Old')->setCode('old');
        $oldOrgProvider = (new IdentityProvider())->setSlug('old-org')->setEnabled(true)->setTenant($oldOrg);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'],
            tenantEnforced: true, tenantProviders: [$operator], userLinked: false, boundTo: $oldOrgProvider,
            globalProviders: true);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response, 'password login must not be reachable');
        self::assertSame('/sso/operator-entra/start', $response->getTargetUrl());
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
        bool $userLinked = true,
        ?IdentityProvider $boundTo = null,
        bool $globalProviders = false,
    ): RequestEvent {
        $userRepository = $this->createMock(UserRepository::class);
        if ($userExists) {
            $tenant = new Tenant();
            $tenant->setSsoEnforced($tenantEnforced);
            $resolvedUser = new User();
            $resolvedUser->setTenant($tenant);
            foreach ($globalProviders ? [] : $tenantProviders as $tenantProvider) {
                $tenantProvider->setTenant($tenantProvider->getTenant() ?? $tenant);
            }
            if ($userLinked && $tenantProviders !== []) {
                $resolvedUser->setSsoProvider($tenantProviders[0]);
            }
            if ($boundTo !== null) {
                $boundTo->setTenant($boundTo->getTenant() ?? $tenant);
                $resolvedUser->setSsoProvider($boundTo);
            }
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
