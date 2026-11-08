<?php

declare(strict_types=1);

namespace App\Security\Token;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Which requests belong to the stateless `api_token` firewall: every request
 * that carries `Authorization: Bearer …`, whatever its path. That firewall then
 * accepts the token only on {@see self::acceptsTokenOn()} — /api and /mcp — and
 * answers 401 everywhere else, so an API token never opens a page of the web UI
 * and never creates a browser session (S1, wave 5).
 */
final class ApiTokenRequest implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return self::isTokenRequest($request);
    }

    public static function isTokenRequest(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    /** The only paths an API token is valid on: the REST API and the assistant endpoint. */
    public static function acceptsTokenOn(string $pathInfo): bool
    {
        return $pathInfo === '/api' || str_starts_with($pathInfo, '/api/') || str_starts_with($pathInfo, '/api.')
            || $pathInfo === '/mcp';
    }
}
