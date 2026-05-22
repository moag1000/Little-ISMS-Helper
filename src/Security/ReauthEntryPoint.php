<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authentication entry point with re-auth challenge for RememberMe sessions.
 *
 * Symfony's `AccessListener` calls the entry point (not the
 * `access_denied_handler`) when a RememberMe-only user accesses a route
 * requiring full authentication. This entry point detects that case and
 * renders the reauth challenge instead of redirecting to /login.
 *
 * For unauthenticated users (no token), falls back to the default
 * FormLoginAuthenticator behavior: redirect to login_path.
 */
final class ReauthEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ReauthChallengeResponder $responder,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $loginPath = 'app_login',
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $challenge = $this->responder->tryRespond($request);
        if ($challenge !== null) {
            return $challenge;
        }

        $loginUrl = $this->urlGenerator->generate($this->loginPath, [
            '_locale' => $request->getLocale(),
        ]);

        return new RedirectResponse($loginUrl);
    }
}
