<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mint + revoke API tokens (read-only for the REST API; optionally allowed to
 * write through the assistant connection, MCP). Only the SHA-256 hash is stored; the
 * plaintext is returned once at mint time and never persisted. Shared by the
 * CLI command and the self-service UI.
 */
final class ApiTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a token for $user; returns the PLAINTEXT (shown once).
     */
    public function mint(User $user, string $label, ?int $expiresDays = null, bool $canWrite = false): string
    {
        $plain = bin2hex(random_bytes(32));

        $token = new ApiToken();
        $token->setUser($user);
        $token->setTenant($user->getTenant());
        $token->setTokenHash(hash('sha256', $plain));
        $token->setLabel($label !== '' ? $label : 'API token');
        $token->setCanWrite($canWrite);
        if ($expiresDays !== null && $expiresDays > 0) {
            $token->setExpiresAt((new DateTimeImmutable())->modify(sprintf('+%d days', $expiresDays)));
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'api_token.created',
            entityType: 'ApiToken',
            entityId: $token->getId(),
            description: sprintf(
                '%s API token "%s" created for %s',
                $canWrite ? 'Assistant-write' : 'Read-only',
                $token->getLabel(),
                $user->getUserIdentifier(),
            ),
        );

        return $plain;
    }

    public function revoke(ApiToken $token): void
    {
        $token->setRevoked(true);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'api_token.revoked',
            entityType: 'ApiToken',
            entityId: $token->getId(),
            description: sprintf('API token "%s" revoked', $token->getLabel()),
        );
    }
}
