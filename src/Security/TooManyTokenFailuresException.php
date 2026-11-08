<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Too many failed API-token attempts from one client (rate limiter `api_auth_failure`). */
final class TooManyTokenFailuresException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Too many failed API token attempts. Try again later.';
    }
}
