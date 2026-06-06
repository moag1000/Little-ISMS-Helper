<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\SystemSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * MFA Enrollment Enforcer — makes the `security.mfa_required_roles` policy real.
 *
 * SECURITY CONTEXT
 * ----------------
 * The admin setting `security.mfa_required_roles` (default
 * ["ROLE_ADMIN","ROLE_SUPER_ADMIN","ROLE_GROUP_CISO"], migration
 * Version20260507193103) declares which roles MUST have a second factor —
 * citing NIS2 Art. 21.2.b + DORA Art. 9. Until now nothing CONSUMED it:
 * {@see MfaEnforcerSubscriber} only challenges users who already enrolled MFA,
 * and {@see App\Security\LoginSuccessHandler} marks un-enrolled users as
 * `mfa_verified` and lets them straight in. A privileged user could therefore
 * run the whole ISMS without ever enabling MFA — the control looked enforced
 * but was dead config.
 *
 * HOW IT WORKS
 * ------------
 * On every authenticated main request (priority 4 — AFTER the firewall (8),
 * SetupRequiredSubscriber (10) and the pending-challenge MfaEnforcerSubscriber
 * (5)): if the user holds any of the required roles (role-hierarchy aware via
 * Security::isGranted) and has NO active MFA token, every route except the MFA
 * enrollment flow, logout and exempt infrastructure is redirected to
 * /profile/mfa. The user is thus forced to enrol before doing anything else.
 *
 * Users without a required role are untouched. Disabling the policy
 * (empty list) disables enforcement.
 *
 * NIS2 Art. 21.2(b) (MFA) · DORA Art. 9 · ISO 27001:2022 A.8.5.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 4)]
final class MfaEnrollmentEnforcerSubscriber
{
    /**
     * Paths always reachable while enrolment is pending: the enrolment flow
     * itself, logout, the (irrelevant here) challenge flow and exempt infra.
     */
    private const ALLOWED_PREFIXES = [
        '/profile/mfa', // enrolment: index, setup-totp, verify, backup codes
        '/mfa',         // challenge/verify flow (harmless for un-enrolled users)
        '/logout',
        '/_wdt',
        '/_profiler',
        '/_error',
        '/login',
        '/oauth',
        '/saml',
        '/setup',
        '/preferences/dismiss', // onboarding banner dismiss — never a trap
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SystemSettingsRepository $systemSettings,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // A pending second-factor challenge is handled by MfaEnforcerSubscriber;
        // never double-redirect. (Un-enrolled users never have this set, but be safe.)
        $session = $request->getSession();
        if ($session->get('_security.mfa_required', false) && !$session->get('_security.mfa_verified', false)) {
            return;
        }

        $requiredRoles = $this->requiredRoles();
        if ($requiredRoles === []) {
            return; // policy disabled
        }

        // Role-hierarchy aware: ROLE_SUPER_ADMIN satisfies a ROLE_ADMIN requirement.
        $userIsRequired = false;
        foreach ($requiredRoles as $role) {
            if ($this->security->isGranted($role)) {
                $userIsRequired = true;
                break;
            }
        }
        if (!$userIsRequired) {
            return;
        }

        if ($user->hasActiveMfaToken()) {
            return; // already enrolled — nothing to enforce
        }

        $path = $request->getPathInfo();
        $normalizedPath = preg_replace('#^/[a-z]{2}(?=/|$)#', '', $path) ?? $path;
        foreach (self::ALLOWED_PREFIXES as $allowedPrefix) {
            if (str_starts_with($normalizedPath, $allowedPrefix)) {
                return;
            }
        }

        $locale = $request->getLocale() ?: ($session->get('_locale', 'de'));

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('warning', 'mfa.enrollment_required_flash');
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_profile_mfa_index', ['_locale' => $locale])
        ));
    }

    /**
     * Decode the `security.mfa_required_roles` JSON setting into a role list.
     *
     * @return string[]
     */
    private function requiredRoles(): array
    {
        // getSetting() may return an already-decoded value (the value column is
        // JSON, so a stored array comes back as an array) or a JSON string.
        $raw = $this->systemSettings->getSetting('security', 'mfa_required_roles', []);
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $r): string => is_string($r) ? trim($r) : '', $decoded),
            static fn (string $r): bool => $r !== '',
        ));
    }
}
