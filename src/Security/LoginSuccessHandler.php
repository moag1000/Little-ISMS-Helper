<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Custom authentication success handler that handles locale-aware redirects.
 *
 * MFA: {@see \App\Security\Mfa\MfaLoginListener} (NIS-2 Art. 21(2)(j)).
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LandingRoute $landingRoute,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        // Get locale from session, browser, or default
        $locale = $request->getSession()->get('_locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        /** @var User $user */
        $user = $token->getUser();

        // The second factor is not this handler's business: MfaLoginListener runs after it on every
        // login and turns this redirect into the target after /mfa-challenge when one is due.

        // Check if there's a target path (requested page before login)
        $targetPath = $request->getSession()->get('_security.main.target_path');

        if ($targetPath) {
            // Clear the target path from session
            $request->getSession()->remove('_security.main.target_path');
            // Re-validate: the stored target may come from a crafted re-auth link.
            if (SafeRedirectTarget::isSafe((string) $targetPath, $request)) {
                return new RedirectResponse($targetPath);
            }
        }

        // Default: the user's landing page (My Day or dashboard), with locale
        $url = $this->urlGenerator->generate($this->landingRoute->routeFor($user), ['_locale' => $locale]);
        return new RedirectResponse($url);
    }
}
