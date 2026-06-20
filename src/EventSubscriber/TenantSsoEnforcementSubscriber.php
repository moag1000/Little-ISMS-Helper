<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\IdentityProvider;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tenant-wide SSO enforcement — makes {@see \App\Entity\Tenant::isSsoEnforced()} real.
 *
 * SECURITY CONTEXT
 * ----------------
 * A Tenant can flip `ssoEnforced = true` ("all users in this organisation must
 * log in via SSO; local passwords are locked"). The flag had a getter/setter and
 * an admin toggle, but no caller, so the policy was dead config: a user in an
 * SSO-enforced tenant could still authenticate with a password.
 *
 * This complements (does not replace) {@see SsoEnforcementSubscriber}, which
 * enforces PER-PROVIDER domain-binding. That policy keys off
 * `IdentityProvider.domainBindingMode = enforce` and the submitted email's
 * domain; THIS one keys off the resolved user's TENANT-level flag. The two run at
 * the same priority on POST /login and either may fire first — both correct
 * outcomes redirect to an SSO start.
 *
 * HOW IT WORKS
 * ------------
 * On a POST to the login check_path (/login), BEFORE the firewall authenticates
 * (priority 9 > firewall's 8): resolve the submitted email → user → tenant. If the
 * tenant has `ssoEnforced = true`, find one of the tenant's ENABLED identity
 * providers and redirect to its SSO start, short-circuiting the password flow.
 *
 * ANTI-LOCKOUT (hard rule)
 * ------------------------
 * FAIL-OPEN whenever enforcement cannot be satisfied — never trap a tenant out of
 * its own application. The password login proceeds untouched if:
 *  - the email is empty / resolves to no user,
 *  - the user has no tenant, or the tenant is not SSO-enforced,
 *  - the tenant has NO enabled SSO provider, or none with a usable slug.
 * The listener only acts on POST, so GET /login always renders — no redirect loop.
 *
 * NIS2 Art. 21.2(b) / ISO 27001:2022 A.5.16 (Identity management).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 9)]
final class TenantSsoEnforcementSubscriber
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly IdentityProviderRepository $identityProviderRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST')) {
            return; // GET /login must always render
        }

        // Match the login check_path (app_login = /login), locale-prefix agnostic.
        $path = $request->getPathInfo();
        $normalizedPath = preg_replace('#^/[a-z]{2}(?=/|$)#', '', $path) ?? $path;
        if ($normalizedPath !== '/login') {
            return;
        }

        $email = trim((string) $request->request->get('_username', ''));
        if ($email === '') {
            return;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        $tenant = $user?->getTenant();
        if ($tenant === null || !$tenant->isSsoEnforced()) {
            return; // not enforced — password login proceeds
        }

        // Pick the tenant's SSO provider to redirect to. Prefer a tenant-scoped
        // provider over a global one (the tenant policy is about THIS tenant's
        // IdP), then the first deterministically-ordered enabled provider with a
        // usable slug.
        $slug = $this->resolveEnforcedSlug($tenant);

        // ANTI-LOCKOUT: SSO-enforced but no usable provider → fail open.
        if ($slug === null) {
            $this->logger?->warning(
                'Tenant has ssoEnforced=true but no enabled SSO provider with a slug; '
                . 'falling back to password login to avoid lockout.',
                ['tenant' => $tenant->getId(), 'email' => $email],
            );

            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_sso_start', ['slug' => $slug])
        ));
    }

    /**
     * Deterministically resolve which enabled provider an SSO-enforced tenant
     * should be redirected to. Tenant-scoped providers win over global ones;
     * the first candidate with a non-empty slug is used. Null = no usable IdP.
     */
    private function resolveEnforcedSlug(\App\Entity\Tenant $tenant): ?string
    {
        $providers = $this->identityProviderRepository->findEnabledForTenant($tenant);

        // Tenant-scoped first, global as fallback — stable within each group
        // because the repository already orders deterministically (tenant, name).
        usort($providers, static function (IdentityProvider $a, IdentityProvider $b): int {
            return ($a->isGlobal() ? 1 : 0) <=> ($b->isGlobal() ? 1 : 0);
        });

        foreach ($providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }
            $slug = $provider->getSlug();
            if ($slug !== null && $slug !== '') {
                return $slug;
            }
        }

        return null;
    }
}
