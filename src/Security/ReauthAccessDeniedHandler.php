<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Instead of a generic 403 for RememberMe-only sessions, redirect users to a
 * re-authentication challenge so they can elevate to IS_AUTHENTICATED_FULLY.
 *
 * Symfony wires this via `access_denied_handler` in the `main` firewall config.
 */
final class ReauthAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $token = $this->security->getToken();

        // Only intercept if the user is authenticated exclusively via RememberMe.
        // A fully-authenticated user who genuinely lacks RBAC permissions should
        // still receive a standard 403.
        if ($token instanceof RememberMeToken) {
            $returnTo = $request->getPathInfo();

            // Safety: strip query-string from returnTo to keep the URL short and
            // free of potential injection. The login form POST will restore the
            // full path after successful authentication via _target_path.
            $loginUrl = $this->urlGenerator->generate('app_login', [
                '_security_reauth' => '1',
                '_security_return_to' => $returnTo,
            ]);

            return new RedirectResponse($loginUrl);
        }

        // Return null → Symfony default 403 handling.
        return null;
    }
}
