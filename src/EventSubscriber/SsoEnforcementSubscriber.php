<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\IdentityProvider;
use App\Repository\UserRepository;
use App\Service\Sso\SsoProviderRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SSO Enforcement — makes a provider's `domainBindingMode = enforce` real.
 *
 * SECURITY CONTEXT
 * ----------------
 * An IdentityProvider can bind an email domain in ENFORCE mode, meaning users of
 * that domain MUST authenticate through that provider. The resolver
 * {@see SsoProviderRegistry::findEnforcedProviderForEmail()} existed but had no
 * caller, so the policy was dead config: an enforced user could still log in with
 * a password.
 *
 * HOW IT WORKS
 * ------------
 * On a POST to the login check_path (/login), BEFORE the firewall authenticates
 * (priority 9 > firewall's 8): read the submitted email, resolve the user's
 * tenant, and ask the registry for an ENABLED enforced provider for that email
 * domain. If one exists, short-circuit the password flow and redirect to that
 * provider's SSO start. The firewall therefore never sees the password attempt.
 *
 * FAIL-OPEN by design: if no enforced provider resolves (none configured, or the
 * provider is disabled), the password login proceeds untouched. The listener only
 * acts on POST, so GET /login always renders — there is no redirect loop (the SSO
 * start route falls back to GET /login on its own errors).
 *
 * NIS2 Art. 21.2(b) / ISO 27001:2022 A.5.16 (Identity management).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 9)]
final class SsoEnforcementSubscriber
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SsoProviderRegistry $ssoProviderRegistry,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST')) {
            return;
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

        // Resolve the user's tenant so tenant-scoped IdPs are considered too.
        // Unknown email → null tenant → only global IdPs are checked (fail-open).
        $user = $this->userRepository->findOneBy(['email' => $email]);
        $tenant = $user?->getTenant();

        $provider = $this->ssoProviderRegistry->findEnforcedProviderForEmail($tenant, $email);
        if (!$provider instanceof IdentityProvider || !$provider->isEnabled()) {
            return; // no enforcement — password login proceeds
        }

        $slug = $provider->getSlug();
        if ($slug === null || $slug === '') {
            return; // misconfigured provider — do not trap the user
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_sso_start', ['slug' => $slug])
        ));
    }
}
