<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Repository\IdentityProviderRepository;
use App\Service\Sso\SsoProviderRegistry;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the login-button visibility rules in SsoProviderRegistry.
 *
 * The repository and tenant-context dependencies are stubbed so the test
 * doesn't need a database — the goal is to pin down the filter logic
 * (domain binding modes, anonymous-vs-tenant scope) precisely.
 */
final class SsoProviderRegistryFilterTest extends TestCase
{
    #[Test]
    public function anonymousVisitorSeesOnlyGlobalProvidersWithoutDomainBinding(): void
    {
        $globalNoBinding = $this->makeProvider('global', tenant: null, mode: IdentityProvider::DOMAIN_MODE_OPTIONAL, bindings: []);
        $globalEnforcedAcme = $this->makeProvider('global-acme', tenant: null, mode: IdentityProvider::DOMAIN_MODE_ENFORCE, bindings: ['acme.com']);
        $tenantScoped = $this->makeProvider('tenant', tenant: new Tenant(), mode: IdentityProvider::DOMAIN_MODE_OPTIONAL, bindings: []);

        $registry = $this->buildRegistry([$globalNoBinding, $globalEnforcedAcme, $tenantScoped]);
        $buttons = $registry->getLoginButtons(null, null);

        self::assertSame(['global'], $this->slugs($buttons));
    }

    #[Test]
    public function anonymousVisitorWithMatchingEmailUnlocksTenantScopedProvider(): void
    {
        $tenantAcme = $this->makeProvider('tenant-acme', tenant: new Tenant(), mode: IdentityProvider::DOMAIN_MODE_OPTIONAL, bindings: ['acme.com']);
        $globalAlways = $this->makeProvider('global', tenant: null, mode: IdentityProvider::DOMAIN_MODE_OPTIONAL, bindings: []);

        $registry = $this->buildRegistry([$tenantAcme, $globalAlways]);
        $buttons = $registry->getLoginButtons(null, 'alice@acme.com');

        self::assertSame(['global', 'tenant-acme'], $this->slugs($buttons));
    }

    #[Test]
    public function enforcedBindingHidesProviderWhenEmailIsMissingOrMismatched(): void
    {
        $globalEnforced = $this->makeProvider('enforced', tenant: null, mode: IdentityProvider::DOMAIN_MODE_ENFORCE, bindings: ['acme.com']);
        $registry = $this->buildRegistry([$globalEnforced]);

        self::assertSame([], $this->slugs($registry->getLoginButtons(null, null)));
        self::assertSame([], $this->slugs($registry->getLoginButtons(null, 'bob@other.com')));
        self::assertSame(['enforced'], $this->slugs($registry->getLoginButtons(null, 'bob@acme.com')));
    }

    /**
     * @param list<IdentityProvider> $providers
     */
    private function buildRegistry(array $providers): SsoProviderRegistry
    {
        $repo = $this->createMock(IdentityProviderRepository::class);
        $repo->method('findAllEnabled')->willReturn($providers);
        $repo->method('findEnabledForTenant')->willReturnCallback(
            fn (?Tenant $t) => array_values(array_filter(
                $providers,
                fn (IdentityProvider $p) => $p->getTenant() === null || $p->getTenant() === $t
            ))
        );

        $context = $this->createMock(TenantContext::class);

        return new SsoProviderRegistry($repo, $context);
    }

    /**
     * @return list<string>
     */
    private function slugs(array $providers): array
    {
        $out = array_map(fn (IdentityProvider $p) => (string) $p->getSlug(), $providers);
        sort($out);
        return $out;
    }

    private function makeProvider(string $slug, ?Tenant $tenant, string $mode, array $bindings): IdentityProvider
    {
        return (new IdentityProvider())
            ->setSlug($slug)
            ->setName(ucfirst($slug))
            ->setClientId('id-' . $slug)
            ->setEnabled(true)
            ->setTenant($tenant)
            ->setDomainBindingMode($mode)
            ->setDomainBindings($bindings);
    }
}
