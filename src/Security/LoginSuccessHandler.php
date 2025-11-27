<?php

namespace App\Security;

use App\Entity\User;
use App\Service\MfaService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Custom authentication success handler that handles locale-aware redirects and MFA challenges
 *
 * NIS2 Compliance: Art. 21.2.b (Multi-Factor Authentication)
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MfaService $mfaService
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

        // NIS2 Compliance: Check if MFA is required for this user
        if ($this->mfaService->userHasMfaEnabled($user)) {
            // Mark session as awaiting MFA verification
            $request->getSession()->set('_security.mfa_required', true);
            $request->getSession()->set('_security.mfa_user_id', $user->getId());

            // Redirect to MFA challenge page
            $url = $this->urlGenerator->generate('app_mfa_challenge', ['_locale' => $locale]);
            return new RedirectResponse($url);
        }

        // Mark session as fully authenticated (no MFA required)
        $request->getSession()->set('_security.mfa_verified', true);

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
