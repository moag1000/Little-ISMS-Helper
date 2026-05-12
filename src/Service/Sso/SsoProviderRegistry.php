<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Repository\IdentityProviderRepository;
use App\Service\TenantContext;
use Symfony\Component\Yaml\Yaml;

/**
 * Runtime registry for active identity providers.
 *
 * Resolves the visible provider list for a request: global IdPs are visible
 * to everyone; tenant-scoped IdPs are only visible inside their tenant.
 * Domain-binding rules filter further when an email is known.
 */
final class SsoProviderRegistry
{
    public function __construct(
        private readonly IdentityProviderRepository $repo,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @return list<IdentityProvider>
     */
    public function getEnabledForCurrentTenant(): array
    {
        return $this->repo->findEnabledForTenant($this->tenantContext->getCurrentTenant());
    }

    /**
     * Providers selectable on a login screen given an optional email hint.
     *
     * Filtering:
     *  - domain mode "disabled" → always show
     *  - domain mode "optional" → show if list empty OR email matches OR no email yet
     *  - domain mode "enforce"  → only show if email is provided AND matches
     *
     * @return list<IdentityProvider>
     */
    public function getLoginButtons(?Tenant $tenant, ?string $emailHint = null): array
    {
        // Anonymous login (tenant === null) can't see tenant-scoped providers
        // by default — but providers with explicit domain bindings can still be
        // discovered via the entered email. So when tenant is unknown we widen
        // the candidate set to all enabled providers and let domain matching
        // filter visibility below.
        $providers = $tenant === null
            ? $this->repo->findAllEnabled()
            : $this->repo->findEnabledForTenant($tenant);
        $out = [];
        foreach ($providers as $p) {
            $isAnonAndScoped = $tenant === null && !$p->isGlobal();

            $mode = $p->getDomainBindingMode();
            if ($mode === IdentityProvider::DOMAIN_MODE_DISABLED) {
                if ($isAnonAndScoped) {
                    // Don't leak tenant-scoped IdPs to anonymous visitors
                    // unless they explicitly bound a domain that matches.
                    continue;
                }
                $out[] = $p;
                continue;
            }
            if ($p->getDomainBindings() === []) {
                if ($mode === IdentityProvider::DOMAIN_MODE_ENFORCE) {
                    continue;
                }
                if ($isAnonAndScoped) {
                    continue;
                }
                $out[] = $p;
                continue;
            }
            if ($emailHint === null || $emailHint === '') {
                if ($mode === IdentityProvider::DOMAIN_MODE_ENFORCE) {
                    continue;
                }
                if ($isAnonAndScoped) {
                    continue;
                }
                $out[] = $p;
                continue;
            }
            if ($p->matchesEmailDomain($emailHint)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Returns the IdP an enforced-binding email MUST use, if any.
     */
    public function findEnforcedProviderForEmail(?Tenant $tenant, string $email): ?IdentityProvider
    {
        foreach ($this->repo->findEnabledForTenant($tenant) as $p) {
            if ($p->getDomainBindingMode() === IdentityProvider::DOMAIN_MODE_ENFORCE
                && $p->matchesEmailDomain($email)
            ) {
                return $p;
            }
        }

        return null;
    }

    public function findOneBySlugForTenant(string $slug, ?Tenant $tenant): ?IdentityProvider
    {
        return $this->repo->findOneBySlugForTenant($slug, $tenant);
    }

    /**
     * Returns the preset definition for a given preset key, or null if unknown.
     *
     * @return array<string,mixed>|null
     */
    public function getPreset(string $presetKey): ?array
    {
        $file = __DIR__ . '/../../../fixtures/sso/presets/' . $presetKey . '.yaml';
        if (!file_exists($file)) {
            return null;
        }
        return Yaml::parseFile($file);
    }

    /**
     * Returns all available preset definitions, keyed by preset name.
     *
     * @return array<string, array<string,mixed>>
     */
    public function getAllPresets(): array
    {
        $out = [];
        foreach (IdentityProvider::VALID_PRESETS as $key) {
            $preset = $this->getPreset($key);
            if ($preset !== null) {
                $out[$key] = $preset;
            }
        }
        return $out;
    }

    /**
     * Pre-fills an IdentityProvider from the preset definition.
     * Only sets fields that are still empty so existing overrides survive.
     */
    public function applyPresetToProvider(IdentityProvider $provider, string $presetKey): void
    {
        $preset = $this->getPreset($presetKey);
        if ($preset === null) {
            return;
        }
        $provider->setPresetType($presetKey);
        if ($provider->getButtonLabel() === null || $provider->getButtonLabel() === '') {
            $provider->setButtonLabel($preset['buttonLabel'] ?? null);
        }
        if ($provider->getButtonIcon() === null || $provider->getButtonIcon() === '') {
            $provider->setButtonIcon($preset['icon'] ?? null);
        }
        if ($provider->getButtonColor() === null || $provider->getButtonColor() === '') {
            $provider->setButtonColor($preset['color'] ?? null);
        }
        if ($provider->getScopes() === [] || $provider->getScopes() === ['openid', 'profile', 'email']) {
            $scopes = $preset['scopes'] ?? ['openid', 'profile', 'email'];
            $provider->setScopes(is_array($scopes) ? array_values($scopes) : ['openid', 'profile', 'email']);
        }
        if ($provider->getAttributeMap() === ['email' => 'email', 'given_name' => 'firstName', 'family_name' => 'lastName']) {
            $map = $preset['attributeMap'] ?? null;
            if (is_array($map)) {
                $provider->setAttributeMap($map);
            }
        }
    }
}
