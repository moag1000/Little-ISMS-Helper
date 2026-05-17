<?php

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\AppException;

/**
 * Thrown when a CSRF token check fails in a service-layer path that
 * cannot use the `#[IsCsrfTokenValid]` attribute (e.g. AJAX endpoints,
 * Stimulus form submissions, bulk-action handlers).
 */
final class CsrfTokenInvalidException extends AppException
{
    public function __construct(
        private readonly string $tokenId,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf('Invalid CSRF token for "%s".', $tokenId),
            0,
            $previous,
        );
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }
}
