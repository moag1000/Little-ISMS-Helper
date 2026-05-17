<?php

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\AppException;

/**
 * Thrown when a controller/service-layer authorization check fails and
 * the caller wants a domain exception rather than Symfony's
 * AccessDeniedException (which is HTTP-coupled).
 */
final class InsufficientPrivilegeException extends AppException
{
    public function __construct(
        private readonly string $requiredPrivilege,
        private readonly ?int $userId = null,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf(
                'Insufficient privilege: "%s" required%s.',
                $requiredPrivilege,
                $userId !== null ? ' (user '.$userId.')' : '',
            ),
            0,
            $previous,
        );
    }

    public function getRequiredPrivilege(): string
    {
        return $this->requiredPrivilege;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
