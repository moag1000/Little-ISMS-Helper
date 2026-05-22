<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

/**
 * Shared responder for re-auth challenges from both:
 *  - ReauthAccessDeniedHandler (AccessDeniedException on fully-auth check)
 *  - ReauthEntryPoint (InsufficientAuthenticationException via AccessListener)
 *
 * AJAX → 403 JSON; full-page navigation → reauth_page.html.twig.
 */
final class ReauthChallengeResponder
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Environment $twig,
    ) {
    }

    public function tryRespond(Request $request): ?Response
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof RememberMeToken) {
            return null;
        }

        /** @var User|null $user */
        $user = $token->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $provider   = $this->resolveProvider($user);
        $returnTo   = $request->getRequestUri();
        $identifier = $user->getEmail() ?? $user->getUserIdentifier();

        $payload = [
            'reauth'          => true,
            'provider'        => $provider,
            'user_identifier' => $identifier,
            'return_to'       => $returnTo,
            'sso_slug'        => $this->resolveSsoSlug($user),
        ];

        if ($this->isAjaxRequest($request)) {
            return new JsonResponse($payload, Response::HTTP_FORBIDDEN);
        }

        $html = $this->twig->render('security/reauth_page.html.twig', [
            'reauth_data'     => $payload,
            'user_identifier' => $identifier,
            'provider'        => $provider,
            'return_to'       => $returnTo,
            'sso_slug'        => $payload['sso_slug'],
        ]);

        return new Response($html, Response::HTTP_FORBIDDEN);
    }

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

        return $ssoProvider?->getSlug();
    }

    private function isAjaxRequest(Request $request): bool
    {
        $accept = $request->headers->get('Accept', '');
        $xhdr   = $request->headers->get('X-Requested-With', '');

        return str_contains($accept, 'application/json')
            || strtolower($xhdr) === 'xmlhttprequest';
    }
}
