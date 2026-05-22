<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Service\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * Re-auth challenge handler for RememberMe sessions.
 *
 * When a fully-authenticated (IS_AUTHENTICATED_FULLY) route is accessed
 * while only a RememberMe token is present, the firewall throws
 * AccessDeniedException. This handler intercepts that and:
 *
 *  - For AJAX/JSON requests → 403 JSON `{ "reauth": true, "provider": "...", ... }`
 *  - For full-page navigation → dedicated reauth HTML page with fa-modal that auto-opens
 *
 * Provider routing:
 *   'local'       → "password" (POST /reauth/password)
 *   'azure_oauth' → "azure_oauth" (GET /reauth/sso/azure_oauth?return_to=...)
 *   'azure_saml'  → "azure_saml" (GET /reauth/sso/azure_saml?return_to=...)
 *   any ssoProvider → "oidc" (GET /reauth/sso/oidc/{slug}?return_to=...)
 *   null / unknown → "password" (fallback)
 */
final class ReauthAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Environment $twig,
        private readonly TenantContext $tenantContext,
        private readonly IdentityProviderRepository $identityProviderRepo,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $token = $this->tokenStorage->getToken();

        // Only intercept RememberMe tokens — other access denials should fall through.
        if (!$token instanceof RememberMeToken) {
            return null;
        }

        /** @var User|null $user */
        $user = $token->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $provider   = $this->resolveProvider($user);
        $returnTo   = $this->resolveReturnTo($request);
        $identifier = $user->getEmail() ?? $user->getUserIdentifier();

        $payload = [
            'reauth'          => true,
            'provider'        => $provider,
            'user_identifier' => $identifier,
            'return_to'       => $returnTo,
            'sso_slug'        => $this->resolveSsoSlug($user),
        ];

        // AJAX detection: Accept header prefers JSON OR explicit X-Requested-With
        if ($this->isAjaxRequest($request)) {
            return new JsonResponse($payload, Response::HTTP_FORBIDDEN);
        }

        // Full-page navigation: render dedicated reauth page (fa-modal auto-opens via Stimulus)
        $html = $this->twig->render('security/reauth_page.html.twig', [
            'reauth_data'     => $payload,
            'user_identifier' => $identifier,
            'provider'        => $provider,
            'return_to'       => $returnTo,
            'sso_slug'        => $payload['sso_slug'],
        ]);

        return new Response($html, Response::HTTP_FORBIDDEN);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function resolveProvider(User $user): string
    {
        $authProvider = $user->getAuthProvider();

        return match ($authProvider) {
            'azure_oauth' => 'azure_oauth',
            'azure_saml'  => 'azure_saml',
            'local', null => 'password',
            default       => $user->getSsoProvider() !== null ? 'oidc' : 'password',
        };
    }

    private function resolveSsoSlug(User $user): ?string
    {
        $ssoProvider = $user->getSsoProvider();
        if ($ssoProvider !== null) {
            return $ssoProvider->getSlug();
        }
        return null;
    }

    private function resolveReturnTo(Request $request): string
    {
        $uri = $request->getRequestUri();

        // Guard against open-redirect: only allow paths on the same host.
        // `getRequestUri()` is always relative (starts with '/'), safe.
        return $uri;
    }

    private function isAjaxRequest(Request $request): bool
    {
        $accept = $request->headers->get('Accept', '');
        $xhdr   = $request->headers->get('X-Requested-With', '');

        return str_contains($accept, 'application/json')
            || strtolower($xhdr) === 'xmlhttprequest';
    }
}
