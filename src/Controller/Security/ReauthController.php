<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Re-authentication endpoints for the modal challenge.
 *
 * POST /reauth/password
 *   → Validates password for local users; on success upgrades the session token
 *     to IS_AUTHENTICATED_FULLY by replacing the RememberMe token with a
 *     fresh UsernamePasswordToken.
 *     Returns JSON { success: true, return_to: "/..." } or 401 JSON on failure.
 *
 * GET /reauth/sso/{provider}
 *   → Initiates SSO re-auth redirect for azure_oauth, azure_saml, or
 *     oidc/<slug>. Stores `return_to` in session so the authenticator's
 *     onAuthenticationSuccess can redirect back to the original page.
 *
 * All endpoints require IS_AUTHENTICATED_REMEMBERED so an unauthenticated
 * visitor cannot hit them (the access_control list in security.yaml covers
 * this via the general /{locale}/ rule, but these routes are locale-free so
 * we gate them explicitly via the attribute below).
 */
#[Route('/reauth', name: 'app_reauth_')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ReauthController extends AbstractController
{
    // Session key used by the OIDC authenticator (GenericOidcAuthenticator)
    // to know where to redirect after a successful SSO flow.
    private const SESSION_REAUTH_RETURN_TO = '_reauth_return_to';

    // Azure OAuth re-auth target path key (consumed by AzureOAuthAuthenticator
    // success handler when it checks for a stored target path).
    private const SESSION_AZURE_REAUTH_RETURN = '_security.main.target_path';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuditLogger $auditLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TenantContext $tenantContext,
    ) {
    }

    // ── Password endpoint ────────────────────────────────────────────────────

    /**
     * POST /reauth/password
     *
     * Body: { "password": "...", "return_to": "/de/..." }
     * Expects JSON response.
     */
    #[Route('/password', name: 'password', methods: ['POST'])]
    public function password(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Parse JSON body (fetch() POST with application/json)
        $data = [];
        $content = $request->getContent();
        if (is_string($content) && $content !== '') {
            $data = json_decode($content, true) ?? [];
        }

        $plainPassword = (string) ($data['password'] ?? $request->request->get('password', ''));
        $returnTo      = $this->sanitizeReturnTo((string) ($data['return_to'] ?? $request->request->get('return_to', '/')));

        if ($plainPassword === '') {
            return new JsonResponse(['error' => 'reauth.error.password_required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $plainPassword)) {
            $this->auditLogger->logCustom('reauth_failed', 'User', $user->getId(), [], ['reason' => 'invalid_password']);
            return new JsonResponse(['error' => 'reauth.error.invalid_password'], Response::HTTP_UNAUTHORIZED);
        }

        // Upgrade the session token: replace RememberMeToken with fully-authenticated token.
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        // Persist the new token in the session (Symfony 7 / security token serialiser).
        $session = $request->getSession();
        $session->set('_security_main', serialize($token));

        $this->auditLogger->logCustom('reauth_success', 'User', $user->getId(), [], ['method' => 'password']);

        return new JsonResponse(['success' => true, 'return_to' => $returnTo]);
    }

    // ── SSO endpoint ─────────────────────────────────────────────────────────

    /**
     * GET /reauth/sso/{provider}?return_to=/...
     *
     * Supported provider values: azure_oauth, azure_saml, oidc
     * For oidc: pass an additional query param `slug=<idp-slug>`.
     */
    #[Route('/sso/{provider}', name: 'sso', methods: ['GET'])]
    public function sso(string $provider, Request $request): Response
    {
        $returnTo = $this->sanitizeReturnTo((string) $request->query->get('return_to', '/'));
        $session  = $request->getSession();

        // Store the return-to path so any authenticator success-handler can
        // read it and redirect back. We use Symfony's built-in target path key
        // so LoginSuccessHandler + AzureOAuthAuthenticator pick it up for free.
        $session->set(self::SESSION_AZURE_REAUTH_RETURN, $returnTo);
        $session->set(self::SESSION_REAUTH_RETURN_TO, $returnTo);

        return match ($provider) {
            'azure_oauth' => $this->redirectToAzureOAuth($request),
            'azure_saml'  => $this->redirectToAzureSaml($request),
            'oidc'        => $this->redirectToOidc($request, $returnTo),
            default       => $this->redirectToRoute('app_login'),
        };
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function redirectToAzureOAuth(Request $request): Response
    {
        // The knpuniversity/oauth2-client-bundle route for Azure is oauth_azure_connect.
        // We just redirect there; on success AzureOAuthAuthenticator lands back
        // on the stored target path (SESSION_AZURE_REAUTH_RETURN).
        $locale = $request->getLocale() ?: 'de';
        $startUrl = $this->urlGenerator->generate('oauth_azure_connect', ['_locale' => $locale]);
        return new RedirectResponse($startUrl);
    }

    private function redirectToAzureSaml(Request $request): Response
    {
        // SAML re-auth: redirect to the SP-initiated SSO start.
        // AzureSamlAuthenticator handles the response callback.
        $locale = $request->getLocale() ?: 'de';
        $startUrl = $this->urlGenerator->generate('saml_login', ['_locale' => $locale]);
        return new RedirectResponse($startUrl);
    }

    private function redirectToOidc(Request $request, string $returnTo): Response
    {
        $slug = (string) $request->query->get('slug', '');
        if ($slug === '') {
            return $this->redirectToRoute('app_login');
        }
        // Use the existing SsoController start route (locale-free path), pass
        // `target` so GenericOidcAuthenticator can store it (SESSION_TARGET_KEY).
        $url = $this->urlGenerator->generate('app_sso_start', [
            'slug'   => $slug,
            'target' => $returnTo,
        ]);
        return new RedirectResponse($url);
    }

    /**
     * Guard against open-redirect: only allow relative paths on the same origin.
     */
    private function sanitizeReturnTo(string $raw): string
    {
        if ($raw === '' || $raw === '/') {
            return '/';
        }
        // Strip scheme/host: any path that starts with // or contains :// is rejected.
        if (str_starts_with($raw, '//') || str_contains($raw, '://')) {
            return '/';
        }
        // Must start with a single slash.
        if (!str_starts_with($raw, '/')) {
            return '/';
        }
        return $raw;
    }
}
