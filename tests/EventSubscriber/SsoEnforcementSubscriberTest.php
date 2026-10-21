<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\EventSubscriber\SsoEnforcementSubscriber;
use App\Repository\UserRepository;
use App\Service\Sso\SsoProviderRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Makes a provider's domainBindingMode=enforce real: a password login for an
 * email whose domain has an ENABLED enforced provider is short-circuited to that
 * provider's SSO start, before the firewall authenticates. Fail-open otherwise.
 */
#[AllowMockObjectsWithoutExpectations]
final class SsoEnforcementSubscriberTest extends TestCase
{
    #[Test]
    public function enforcedEmailIsRedirectedToSso(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $provider);

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

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $provider, userLinked: false);
        self::assertNull($event->getResponse());

        $provider->setAllowEmailLinking(true);
        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $provider, userLinked: false);
        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    #[Test]
    public function linkedUserIsRedirectedEvenWhenProviderDoesNotLinkByEmail(): void
    {
        $provider = (new IdentityProvider())->setSlug('acme-entra')->setEnabled(true)->setAllowEmailLinking(false);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $provider, userLinked: true);
        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    #[Test]
    public function userLinkedToAnotherEnabledProviderIsRedirectedThere(): void
    {
        $enforced = (new IdentityProvider())->setSlug('acme-entra')->setEnabled(true)->setAllowEmailLinking(false);
        $other = (new IdentityProvider())->setSlug('legacy-idp')->setEnabled(true);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $enforced,
            userLinked: false, boundTo: $other);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/sso/legacy-idp/start', $response->getTargetUrl());
    }

    #[Test]
    public function userLinkedToDisabledProviderFailsOpenWhenEnforcedProviderRefuses(): void
    {
        $enforced = (new IdentityProvider())->setSlug('acme-entra')->setEnabled(true)->setAllowEmailLinking(true);
        $disabled = (new IdentityProvider())->setSlug('legacy-idp')->setEnabled(false);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $enforced,
            userLinked: false, boundTo: $disabled);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function tenantOwnedProviderCannotSwitchOffOperatorDomainEnforcement(): void
    {
        // Operator: global provider enforces the domain. A tenant admin adds an
        // own provider (linking on) and its users sign in through it once. The
        // users must still end at an SSO provider, never at the password login.
        $tenant = (new \App\Entity\Tenant())->setName('Acme')->setCode('acme');
        $operatorEnforced = (new IdentityProvider())->setSlug('operator-entra')->setEnabled(true)
            ->setDomainBindingMode(IdentityProvider::DOMAIN_MODE_ENFORCE);
        $tenantOwn = (new IdentityProvider())->setSlug('tenant-own')->setEnabled(true)
            ->setAllowEmailLinking(true)->setTenant($tenant);
        $user = (new User())->setTenant($tenant);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $operatorEnforced,
            userLinked: false, boundTo: $tenantOwn, user: $user);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response, 'password login must not be reachable');
        self::assertStringStartsWith('/sso/', $response->getTargetUrl());
    }

    #[Test]
    public function noEnforcedProviderFallsThroughToPassword(): void
    {
        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@nowhere.de'], null);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function disabledEnforcedProviderDoesNotTrapUser(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('broken');
        $provider->setEnabled(false);

        $event = $this->dispatch('/de/login', 'POST', ['_username' => 'bob@acme.de'], $provider);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function getLoginIsNeverIntercepted(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch('/de/login', 'GET', [], $provider);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function emptyEmailIsIgnored(): void
    {
        $event = $this->dispatch('/de/login', 'POST', ['_username' => ''], null);
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function nonLoginPathIsIgnored(): void
    {
        $provider = new IdentityProvider();
        $provider->setSlug('acme-entra');
        $provider->setEnabled(true);

        $event = $this->dispatch('/de/dashboard', 'POST', ['_username' => 'bob@acme.de'], $provider);
        self::assertNull($event->getResponse());
    }

    /**
     * @param array<string, string> $params
     */
    private function dispatch(
        string $path,
        string $method,
        array $params,
        ?IdentityProvider $enforcedProvider,
        bool $userLinked = true,
        ?IdentityProvider $boundTo = null,
        ?User $user = null,
    ): RequestEvent {
        $user ??= new User();
        if ($userLinked && $enforcedProvider !== null) {
            $user->setSsoProvider($enforcedProvider);
        }
        if ($boundTo !== null) {
            $user->setSsoProvider($boundTo);
        }
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);

        $registry = $this->createMock(SsoProviderRegistry::class);
        $registry->method('findEnforcedProviderForEmail')->willReturn($enforcedProvider);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $p = []): string => '/sso/' . ($p['slug'] ?? '') . '/start',
        );

        $subscriber = new SsoEnforcementSubscriber($userRepository, $registry, $urlGenerator);

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
