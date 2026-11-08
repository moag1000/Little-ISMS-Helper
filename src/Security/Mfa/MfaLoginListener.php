<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use App\Entity\User;
use App\Security\SafeRedirectTarget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Every successful login of the web firewall starts a fresh MFA state for the person who logged in
 * — form login, SSO (Azure OAuth, SAML, OIDC) and remember-me alike.
 *
 * - A person with an active second factor is pending; the login's own redirect becomes the target
 *   after /mfa-challenge. SSO logins ask for the local factor too, when the person enrolled one:
 *   the enrolment policy ({@see \App\EventSubscriber\MfaEnrollmentEnforcerSubscriber}) makes
 *   privileged SSO users enrol, so their factor must also be asked.
 * - "Stay logged in" waits: the cookie is issued by {@see \App\Controller\MfaLoginController} after
 *   the second factor, never with the password answer. Priority -48 sits between Symfony's
 *   CheckRememberMeConditionsListener (-32, enables the badge) and RememberMeListener (-64, sets
 *   the cookie).
 * - A remember-me login counts as both factors: its cookie exists only after the second factor,
 *   and its signature covers the person's active second factors (`mfaRememberMeMarker` in
 *   security.yaml), so a cookie from before an enrolment or a device change is void.
 *
 * Re-authentication with the password ({@see \App\Controller\Security\ReauthController}) is no
 * login event and calls {@see MfaSessionState::begin()} itself.
 */
#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess', priority: -48)]
final class MfaLoginListener
{
    public const string FIREWALL = 'main';

    public function __construct(
        private readonly MfaSessionState $state,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::FIREWALL) {
            return; // api_token firewall: stateless, the token is the credential
        }
        $user = $event->getUser();
        $request = $event->getRequest();
        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();

        $viaCookie = $event->getAuthenticatedToken() instanceof RememberMeToken;
        if (!$this->state->begin($session, $user, $viaCookie)) {
            return;
        }

        $passport = $event->getPassport();
        if ($passport->hasBadge(RememberMeBadge::class)) {
            /** @var RememberMeBadge $badge */
            $badge = $passport->getBadge(RememberMeBadge::class);
            if ($badge->isEnabled()) {
                $badge->disable();
                $this->state->rememberAfterVerification($session, $user);
            }
        }

        $response = $event->getResponse();
        if ($response instanceof RedirectResponse && SafeRedirectTarget::isSafe($response->getTargetUrl(), $request)) {
            $session->set('_security.main.target_path', $response->getTargetUrl());
        }

        $locale = $session->get('_locale') ?? $request->getPreferredLanguage(['de', 'en']) ?? 'de';
        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_mfa_challenge', ['_locale' => $locale]),
        ));
    }
}
