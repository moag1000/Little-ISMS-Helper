<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\IdentityProvider;
use App\Service\Sso\OidcDiscoveryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AJAX endpoint for on-blur discovery URL validation in the SSO wizard.
 *
 * Accepts a discovery URL (from the wizard step 2 form) and attempts to
 * fetch the OpenID Connect discovery document. Returns status + discovered
 * endpoints so the UI can show a live preview without page reload.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/api/sso', name: 'api_sso_')]
#[IsGranted('ROLE_ADMIN')]
final class SsoDiscoveryApiController extends AbstractController
{
    public function __construct(
        private readonly OidcDiscoveryService $discovery,
    ) {
    }

    /**
     * POST /api/sso/validate-discovery
     * Body: { "discoveryUrl": "https://..." }
     * Returns: { "ok": true, "issuer": "...", "endpoints": {...} }
     *       or { "ok": false, "error": "..." }
     */
    #[Route('/validate-discovery', name: 'validate_discovery', methods: ['POST'])]
    public function validateDiscovery(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        $discoveryUrl = is_array($data) ? (string) ($data['discoveryUrl'] ?? '') : '';

        if ($discoveryUrl === '') {
            return $this->json(['ok' => false, 'error' => 'Discovery URL is required.'], 422);
        }

        if (!filter_var($discoveryUrl, FILTER_VALIDATE_URL) || !str_starts_with($discoveryUrl, 'https://')) {
            return $this->json(['ok' => false, 'error' => 'URL must be a valid HTTPS URL.'], 422);
        }

        // Build a temporary entity to leverage OidcDiscoveryService
        $provider = new IdentityProvider();
        $provider->setDiscoveryUrl($discoveryUrl);

        try {
            $doc = $this->discovery->fetchDiscovery($provider);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => 'Discovery fetch failed: ' . $e->getMessage(),
            ]);
        }

        return $this->json([
            'ok' => true,
            'issuer' => $doc['issuer'] ?? null,
            'endpoints' => [
                'authorization' => $doc['authorization_endpoint'] ?? null,
                'token' => $doc['token_endpoint'] ?? null,
                'userinfo' => $doc['userinfo_endpoint'] ?? null,
                'jwks_uri' => $doc['jwks_uri'] ?? null,
            ],
            'scopes_supported' => $doc['scopes_supported'] ?? null,
        ]);
    }
}
