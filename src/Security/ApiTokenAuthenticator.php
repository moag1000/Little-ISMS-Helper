<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use App\Security\Token\ApiTokenRequest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates a Bearer API token against the api_tokens table.
 *
 * Runs only in the stateless `api_token` firewall (security.yaml), which takes
 * every request with a Bearer header ({@see ApiTokenRequest}). A token counts
 * only on /api and /mcp; on any other path, and for a token whose organisation
 * is not its user's, the answer is 401. A deactivated user is refused by
 * {@see UserChecker} before this passport is accepted. Being stateless, the
 * firewall never writes the token into a session: no cookie can stand in for
 * the token afterwards.
 *
 * Read-only enforcement is handled separately by {@see \App\EventSubscriber\ReadOnlyApiSubscriber};
 * this authenticator only establishes WHO the request is (the token's user, and
 * therefore the tenant). Only the SHA-256 hash of the presented token is looked
 * up — the plaintext is never stored.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /** Name of the firewall this authenticator belongs to (security.yaml). */
    public const string FIREWALL = 'api_token';

    /** Request attribute carrying the ApiToken entity (MCP audit + organisation opt-in). */
    public const string TOKEN_ATTRIBUTE = '_api_token';

    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        return ApiTokenRequest::isTokenRequest($request);
    }

    public function authenticate(Request $request): Passport
    {
        if (!ApiTokenRequest::acceptsTokenOn($request->getPathInfo())) {
            throw new CustomUserMessageAuthenticationException('API tokens are accepted only on /api and /mcp.');
        }

        $header = (string) $request->headers->get('Authorization', '');
        $plain = trim(substr($header, 7));
        if ($plain === '') {
            throw new CustomUserMessageAuthenticationException('Empty API token.');
        }

        $token = $this->tokenRepository->findOneByHash(hash('sha256', $plain));
        if ($token === null || !$token->isValid(new DateTimeImmutable())) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired API token.');
        }

        $user = $token->getUser();
        if ($user === null) {
            throw new CustomUserMessageAuthenticationException('API token has no associated user.');
        }
        $tokenTenant = $token->getTenant();
        if ($tokenTenant !== null && $user->getTenant()?->getId() !== $tokenTenant->getId()) {
            // The user moved to another organisation after the token was made.
            throw new CustomUserMessageAuthenticationException('API token belongs to another organisation.');
        }

        $token->setLastUsedAt(new DateTimeImmutable());
        $this->entityManager->flush();
        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // continue to the controller
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => 'Authentication failed', 'detail' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="little-isms-helper"'],
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'Authentication required', 'detail' => 'Send an API token as "Authorization: Bearer <token>".'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="little-isms-helper"'],
        );
    }
}
