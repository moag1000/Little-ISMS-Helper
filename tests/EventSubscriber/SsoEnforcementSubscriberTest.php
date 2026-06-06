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
    ): RequestEvent {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(new User());

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
