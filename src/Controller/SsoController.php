<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IdentityProvider;
use App\Security\GenericOidcAuthenticator;
use App\Repository\IdentityProviderRepository;
use App\Service\Sso\OidcAuthenticationFlow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Public SSO routes — `/sso/{slug}/start` triggers the auth flow,
 * `/sso/{slug}/callback` is consumed by GenericOidcAuthenticator.
 *
 * NOTE: These routes are explicitly registered without locale prefix and
 * declared as PUBLIC_ACCESS in security.yaml.
 */
final class SsoController extends AbstractController
{
    public function __construct(
        private readonly IdentityProviderRepository $providerRepo,
        private readonly OidcAuthenticationFlow $flow,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * `?provider=<id>` identifies the provider unambiguously (enforcement
     * redirects, login buttons, re-auth); the slug must match it. Without it
     * (legacy links) the slug is served only when exactly one enabled
     * provider carries it — slugs are unique per organisation only, and a
     * colliding slug of another organisation must never steer the visitor to
     * that organisation's IdP (#1059 I-5). Every failure gets the same
     * neutral message.
     */
    #[Route('/sso/{slug}/start', name: 'app_sso_start', methods: ['GET'])]
    public function start(string $slug, Request $request): Response
    {
        $providerId = $request->query->get('provider');
        if (is_string($providerId) && ctype_digit($providerId)) {
            $provider = $this->providerRepo->find((int) $providerId);
            if ($provider instanceof IdentityProvider && $provider->getSlug() !== $slug) {
                $provider = null;
            }
        } else {
            $candidates = $this->providerRepo->findEnabledBySlug($slug);
            $provider = count($candidates) === 1 ? $candidates[0] : null;
        }
        if (!$provider instanceof IdentityProvider || !$provider->isEnabled()) {
            $this->addFlash('error', 'SSO-Provider not found or disabled.');
            return $this->redirectToRoute('app_login');
        }

        $redirectUri = $this->router->generate('app_sso_callback', ['slug' => $slug], RouterInterface::ABSOLUTE_URL);
        $authReq = $this->flow->buildAuthorizationRequest($provider, $redirectUri);

        $session = $request->getSession();
        $session->set(GenericOidcAuthenticator::SESSION_STATE_KEY, $authReq['state']);
        $session->set(GenericOidcAuthenticator::SESSION_VERIFIER_KEY, $authReq['codeVerifier']);
        $session->set(GenericOidcAuthenticator::SESSION_PROVIDER_KEY, $slug);
        $session->set(GenericOidcAuthenticator::SESSION_PROVIDER_ID_KEY, $provider->getId());
        $target = $request->query->get('target');
        if (is_string($target) && $target !== '') {
            $session->set(GenericOidcAuthenticator::SESSION_TARGET_KEY, $target);
        }

        return new RedirectResponse($authReq['url']);
    }

    /**
     * Callback target — consumed by GenericOidcAuthenticator (firewall).
     * Method body unreachable when authenticator triggers; defensive fallback.
     */
    #[Route('/sso/{slug}/callback', name: 'app_sso_callback', methods: ['GET'])]
    public function callback(string $slug): Response
    {
        return $this->redirectToRoute('app_login');
    }
}
