<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IdentityProvider;
use App\Security\GenericOidcAuthenticator;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\Sso\SsoProviderRegistry;
use App\Service\TenantContext;
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
        private readonly SsoProviderRegistry $registry,
        private readonly OidcAuthenticationFlow $flow,
        private readonly TenantContext $tenantContext,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/sso/{slug}/start', name: 'app_sso_start', methods: ['GET'])]
    public function start(string $slug, Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $provider = $this->registry->findOneBySlugForTenant($slug, $tenant);
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
