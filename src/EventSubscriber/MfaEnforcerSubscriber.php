<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\Mfa\MfaLoginListener;
use App\Security\Mfa\MfaSessionState;
use App\Security\SetupAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * MFA Enforcer Subscriber — closes the MFA bypass vulnerability (CVSS 8.6).
 *
 * SECURITY CONTEXT
 * ----------------
 * Every login writes a fresh MFA state for the person who logged in
 * ({@see MfaLoginListener}, {@see MfaSessionState}). Without this subscriber
 * nothing prevented an authenticated user from simply navigating to any other
 * URL and skipping the second factor entirely.
 *
 * HOW IT WORKS
 * ------------
 * On every main kernel request (priority 5, AFTER the Symfony firewall at 8
 * and after SetupRequiredSubscriber at 10, but before regular controllers):
 *
 *   1. Skip sub-requests, profiler/debug routes and other exempt paths.
 *   2. If the state of the logged-in person (the impersonator while switched)
 *      is not verified, the second factor is outstanding. No state for this
 *      person — another person's state, a session from before the update — is
 *      decided again here: with an active second factor it is pending (fail closed).
 *   3. If the current path is not one of the explicitly allowed MFA-flow paths
 *      the request is redirected to app_mfa_challenge with the current locale.
 *
 * NIS2 Compliance: Art. 21.2.b (Multi-Factor Authentication)
 * ISO 27001:2022: A.8.5 (Secure Authentication)
 *
 * Priority 5: AFTER Symfony firewall (priority 8) and SetupRequiredSubscriber (10)
 * so that $security->getUser() is already resolved from the session.
 * SetupSecuritySubscriber runs at priority 4, meaning we run BEFORE it,
 * but that is intentional — MFA enforcement must happen before setup
 * access checks to prevent escalation paths.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 5)]
final class MfaEnforcerSubscriber
{
    /**
     * Path prefixes that are always allowed while MFA is pending.
     * These cover the MFA challenge/verify flow, logout, Symfony debug toolbar,
     * profiler, error pages, and the public security/OAuth/SAML routes.
     */
    private const ALLOWED_PREFIXES = [
        '/mfa-challenge', // the challenge itself (and locale variants /{de,en}/mfa-…)
        '/mfa-verify',
        '/logout',      // allow logout so user can abort the session
        '/_wdt',        // Symfony web debug toolbar (dev)
        '/_profiler',   // Symfony profiler (dev)
        '/_error',      // Error pages
        '/login',       // login page itself (edge case)
        '/oauth',       // Azure/OAuth flow
        '/saml',        // SAML flow
    ];

    /** Setup wizard paths: allowed only while the installation is not set up yet. */
    private const SETUP_PREFIX = '/setup';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MfaSessionState $mfaState,
        private readonly SetupAccessChecker $setupAccessChecker,
    ) {
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

        // Only the web firewall: API tokens (stateless firewall) are their own credential.
        if ($this->security->getFirewallConfig($request)?->getName() !== MfaLoginListener::FIREWALL) {
            return;
        }

        $subject = MfaSessionState::subjectOf($this->security->getToken());
        if (!$subject instanceof User) {
            return;
        }

        $session = $request->getSession();
        if ($this->mfaState->isVerified($session, $subject)) {
            return;
        }
        if (!$this->mfaState->isPending($session, $subject) && !$this->mfaState->begin($session, $subject)) {
            return; // no state for this person yet and no second factor enrolled
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
        // After setup the wizard is ROLE_SUPER_ADMIN only — a password alone must not reach it.
        if (str_starts_with($normalizedPath, self::SETUP_PREFIX) && !$this->setupAccessChecker->isSetupComplete()) {
            return;
        }

        // Determine locale for redirect target (fall back to session locale or 'de')
        $locale = $request->getLocale()
            ?: ($session->get('_locale', 'de'));

        // Redirect to MFA challenge — user must complete the second factor first
        $challengeUrl = $this->urlGenerator->generate('app_mfa_challenge', ['_locale' => $locale]);
        $event->setResponse(new RedirectResponse($challengeUrl));
    }
}
