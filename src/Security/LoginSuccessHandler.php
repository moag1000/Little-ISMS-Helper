<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Custom authentication success handler that handles locale-aware redirects
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        // Get locale from session, browser, or default
        $locale = $request->getSession()->get('_locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        // Check if there's a target path (requested page before login)
        $targetPath = $request->getSession()->get('_security.main.target_path');

        if ($targetPath) {
            // Clear the target path from session
            $request->getSession()->remove('_security.main.target_path');
            return new RedirectResponse($targetPath);
        }

        // Default: redirect to dashboard with locale
        $url = $this->urlGenerator->generate('app_dashboard', ['_locale' => $locale]);
        return new RedirectResponse($url);
    }
}
