<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use App\Service\Sso\SsoAccountLinkPolicy;
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
 *  - the tenant has NO enabled SSO provider, or none with a usable slug,
 *  - the provider would refuse this account (not linked yet and linking by
 *    e-mail refused, or a privileged / out-of-tree account) — #1059.
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
        if ($user === null) {
            return;
        }

        $target = $this->enforcementTarget($user);
        if ($target === null) {
            return; // not enforced — password login proceeds
        }
        if ($target === false) {
            // ANTI-LOCKOUT: no usable provider, or no enabled provider can sign
            // this account in (not linked + linking refused, never-privileged /
            // tree rule) → fail open.
            $this->logger?->warning(
                'Tenant has ssoEnforced=true but no enabled SSO provider can sign this user in; '
                . 'falling back to password login to avoid lockout.',
                ['tenant' => $user->getTenant()?->getId(), 'email' => $email],
            );

            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_sso_start', ['slug' => $target->getSlug()])
        ));
    }

    /**
     * Tenant-level enforcement decision for $user: null = not enforced;
     * IdentityProvider = redirect target (the enforced provider, or the
     * provider the user is bound to — {@see SsoAccountLinkPolicy::resolveLoginProvider()});
     * false = enforced but exempt (fail open). Also read by the SSO admin page.
     */
    public function enforcementTarget(User $user): IdentityProvider|false|null
    {
        $tenant = $user->getTenant();
        if ($tenant === null || !$tenant->isSsoEnforced()) {
            return null;
        }
        // Tenant-scoped provider first, then global (see resolveEnforcedProvider()).
        $provider = $this->resolveEnforcedProvider($tenant);
        if ($provider === null) {
            return false;
        }

        return SsoAccountLinkPolicy::resolveLoginProvider($provider, $user) ?? false;
    }

    /**
     * Deterministically resolve which enabled provider an SSO-enforced tenant
     * should be redirected to. Tenant-scoped providers win over global ones;
     * the first candidate with a non-empty slug is used. Null = no usable IdP.
     */
    private function resolveEnforcedProvider(\App\Entity\Tenant $tenant): ?IdentityProvider
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
                return $provider;
            }
        }

        return null;
    }
}
