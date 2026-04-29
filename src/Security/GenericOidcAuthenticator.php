<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\IdentityProvider;
use App\Entity\SsoUserApproval;
use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Symfony Authenticator for `/sso/{slug}/callback`.
 *
 * The heavy lifting (state validation, token exchange, ID-Token verification,
 * user provisioning, approval-queue routing) lives in OidcAuthenticationFlow.
 * This class only adapts that flow into Symfony's authenticator contract.
 */
final class GenericOidcAuthenticator extends AbstractAuthenticator
{
    public const SESSION_STATE_KEY = '_sso_oidc_state';
    public const SESSION_VERIFIER_KEY = '_sso_oidc_verifier';
    public const SESSION_PROVIDER_KEY = '_sso_oidc_provider';
    public const SESSION_TARGET_KEY = '_sso_oidc_target';

    public function __construct(
        private readonly IdentityProviderRepository $providerRepo,
        private readonly OidcAuthenticationFlow $flow,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
        private readonly TenantContext $tenantContext,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_sso_callback';
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();

        $error = $request->query->get('error');
        if (is_string($error) && $error !== '') {
            $description = (string) $request->query->get('error_description', '');
            throw new AuthenticationException(trim($error . ': ' . $description, ': '));
        }

        $code = (string) $request->query->get('code', '');
        $state = (string) $request->query->get('state', '');
        if ($code === '' || $state === '') {
            throw new AuthenticationException('Missing code or state on callback.');
        }

        $expectedState = (string) $session->get(self::SESSION_STATE_KEY, '');
        $verifier = (string) $session->get(self::SESSION_VERIFIER_KEY, '');
        $providerSlug = (string) $session->get(self::SESSION_PROVIDER_KEY, '');
        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            throw new AuthenticationException('SSO state mismatch.');
        }
        if ($verifier === '' || $providerSlug === '') {
            throw new AuthenticationException('SSO session lost.');
        }
        $session->remove(self::SESSION_STATE_KEY);
        $session->remove(self::SESSION_VERIFIER_KEY);
        $session->remove(self::SESSION_PROVIDER_KEY);

        $slug = (string) $request->attributes->get('slug', '');
        if ($slug !== $providerSlug) {
            throw new AuthenticationException('SSO provider slug mismatch.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $provider = $this->providerRepo->findOneBySlugForTenant($slug, $tenant);
        if (!$provider instanceof IdentityProvider || !$provider->isEnabled()) {
            throw new AuthenticationException('SSO provider not found or disabled.');
        }

        $redirectUri = $this->router->generate('app_sso_callback', ['slug' => $slug], RouterInterface::ABSOLUTE_URL);
        $result = $this->flow->handleCallback($provider, $redirectUri, $code, $verifier);

        if ($result instanceof SsoUserApproval) {
            // Stash for the failure handler so it can show a friendly screen.
            $session->getFlashBag()->add('sso_pending', $result->getEmail() ?? '');
            throw new AuthenticationException('Your SSO account is awaiting administrator approval.');
        }

        $email = $result->getEmail();
        if ($email === null) {
            throw new AuthenticationException('Provisioned user has no email.');
        }

        return new SelfValidatingPassport(new UserBadge($email, fn () => $result));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = (string) $request->getSession()->remove(self::SESSION_TARGET_KEY);
        if ($target === '') {
            $target = $this->router->generate('app_home');
        }
        return new RedirectResponse($target);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('SSO authentication failure', ['error' => $exception->getMessage()]);
        $loginUrl = $this->router->generate('app_login', ['error' => $exception->getMessage()]);

        return new RedirectResponse($loginUrl);
    }
}
