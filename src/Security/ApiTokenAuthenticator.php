<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
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
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * F6 — authenticates a Bearer API token against the api_tokens table.
 *
 * Read-only enforcement is handled separately by {@see \App\EventSubscriber\ReadOnlyApiSubscriber};
 * this authenticator only establishes WHO the request is (the token's user, and
 * therefore the tenant). Only the SHA-256 hash of the presented token is looked
 * up — the plaintext is never stored.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
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

        $token->setLastUsedAt(new DateTimeImmutable());
        $this->entityManager->flush();

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
        );
    }
}
