<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\ApiToken;
use App\Entity\Tenant;
use App\Entity\User;

/**
 * Who calls the assistant connection: the token's user in the token's
 * organisation. Tools read only through this, never from the request.
 */
final readonly class McpSession
{
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public ApiToken $token,
        public string $baseUrl,
        public string $locale = 'de',
    ) {
    }

    /** Absolute URL of an app path, so the assistant can link the record. */
    public function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
