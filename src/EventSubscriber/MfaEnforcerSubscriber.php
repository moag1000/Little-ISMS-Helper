<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * MFA Enforcer Subscriber — closes the MFA bypass vulnerability (CVSS 8.6).
 *
 * SECURITY CONTEXT
 * ----------------
 * After successful password login, LoginSuccessHandler stores
 *   _security.mfa_required = true
 * in the session and redirects to /mfa-challenge. Without this subscriber
 * nothing prevented an authenticated user from simply navigating to any other
 * URL and skipping the second factor entirely.
 *
 * HOW IT WORKS
 * ------------
 * On every main kernel request (priority 5, AFTER the Symfony firewall at 8
 * and after SetupRequiredSubscriber at 10, but before regular controllers):
 *
 *   1. Skip sub-requests, profiler/debug routes and other exempt paths.
 *   2. If the session carries  _security.mfa_required=true  AND
 *      _security.mfa_verified is absent/false, the user has not yet completed
 *      the second factor.
 *   3. If the current path is not one of the explicitly allowed MFA-flow paths
 *      the request is redirected to app_mfa_challenge with the current locale.
 *
 * NIS2 Compliance: Art. 21.2.b (Multi-Factor Authentication)
 * ISO 27001:2022: A.8.5 (Secure Authentication)
 */
class MfaEnforcerSubscriber implements EventSubscriberInterface
{
    /**
     * Path prefixes that are always allowed while MFA is pending.
     * These cover the MFA challenge/verify flow, logout, Symfony debug toolbar,
     * profiler, error pages, and the public security/OAuth/SAML routes.
     */
    private const ALLOWED_PREFIXES = [
        '/mfa',         // /mfa-challenge, /mfa-verify (and locale variants /{de,en}/mfa-…)
        '/logout',      // allow logout so user can abort the session
        '/_wdt',        // Symfony web debug toolbar (dev)
        '/_profiler',   // Symfony profiler (dev)
        '/_error',      // Error pages
        '/login',       // login page itself (edge case)
        '/oauth',       // Azure/OAuth flow
        '/saml',        // SAML flow
        '/setup',       // setup wizard (handled by its own subscriber)
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 5: AFTER Symfony firewall (priority 8) and SetupRequiredSubscriber (10)
        // so that $security->getUser() is already resolved from the session.
        // SetupSecuritySubscriber runs at priority 4, meaning we run BEFORE it,
        // but that is intentional — MFA enforcement must happen before setup
        // access checks to prevent escalation paths.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only act on the main (master) request, never sub-requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Skip if there is no session (e.g. API/CLI synthetic requests)
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        // Fast-exit: MFA is not pending in this session
        if (!$session->get('_security.mfa_required', false)) {
            return;
        }

        // Fast-exit: MFA has already been verified in this session
        if ($session->get('_security.mfa_verified', false)) {
            return;
        }

        // Only enforce for authenticated users (safety guard — in practice
        // _security.mfa_required is only set after password authentication)
        if (!($this->security->getUser() instanceof UserInterface)) {
            return;
        }

        $path = $request->getPathInfo();

        // Strip the optional locale prefix (/{de|en}) so that prefix-agnostic
        // matching works for both /mfa-challenge and /de/mfa-challenge.
        $normalizedPath = preg_replace('#^/[a-z]{2}(?=/|$)#', '', $path) ?? $path;

        // Allow requests that are part of the MFA flow or exempted infrastructure
        foreach (self::ALLOWED_PREFIXES as $allowedPrefix) {
            if (str_starts_with($normalizedPath, $allowedPrefix)) {
                return;
            }
        }

        // Determine locale for redirect target (fall back to session locale or 'de')
        $locale = $request->getLocale()
            ?: ($session->get('_locale', 'de'));

        // Redirect to MFA challenge — user must complete the second factor first
        $challengeUrl = $this->urlGenerator->generate('app_mfa_challenge', ['_locale' => $locale]);
        $event->setResponse(new RedirectResponse($challengeUrl));
    }
}
