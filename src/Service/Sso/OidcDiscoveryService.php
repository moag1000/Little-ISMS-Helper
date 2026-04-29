<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches OpenID-Connect discovery documents and JWKS.
 *
 * Cache TTL: 1 hour for discovery, 1 hour for JWKS. JWKS may be re-fetched
 * on demand when an unknown kid is encountered during ID-Token verification.
 */
final class OidcDiscoveryService
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch the discovery document for an IdP. Either uses the configured
     * `discoveryUrl` or constructs `<issuer>/.well-known/openid-configuration`.
     *
     * @return array<string,mixed>
     */
    public function fetchDiscovery(IdentityProvider $provider): array
    {
        $url = $provider->getDiscoveryUrl();
        if ($url === null || $url === '') {
            $issuer = $provider->getIssuer();
            if ($issuer === null || $issuer === '') {
                throw new RuntimeException('Provider has neither discoveryUrl nor issuer configured.');
            }
            $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        }

        $cacheKey = 'sso.discovery.' . hash('sha256', $url);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($url): array {
            $item->expiresAfter(self::CACHE_TTL);
            $resp = $this->http->request('GET', $url, ['timeout' => 10]);
            if ($resp->getStatusCode() !== 200) {
                throw new RuntimeException(sprintf('Discovery fetch failed: HTTP %d for %s', $resp->getStatusCode(), $url));
            }
            $payload = json_decode($resp->getContent(false), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Discovery response was not a JSON object.');
            }

            return $payload;
        });
    }

    /**
     * Fetch the JWKS keyset for an IdP. Falls back to discovery doc if not cached.
     *
     * @return array<string,mixed>
     */
    public function fetchJwks(IdentityProvider $provider, bool $forceRefresh = false): array
    {
        $jwksUri = $provider->getJwksUri();
        if ($jwksUri === null || $jwksUri === '') {
            $discovery = $this->fetchDiscovery($provider);
            $jwksUri = $discovery['jwks_uri'] ?? null;
            if (!is_string($jwksUri) || $jwksUri === '') {
                throw new RuntimeException('Provider discovery did not return jwks_uri.');
            }
        }
        $cacheKey = 'sso.jwks.' . hash('sha256', $jwksUri);

        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($jwksUri): array {
            $item->expiresAfter(self::CACHE_TTL);
            $resp = $this->http->request('GET', $jwksUri, ['timeout' => 10]);
            if ($resp->getStatusCode() !== 200) {
                throw new RuntimeException(sprintf('JWKS fetch failed: HTTP %d for %s', $resp->getStatusCode(), $jwksUri));
            }
            $payload = json_decode($resp->getContent(false), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload['keys'])) {
                throw new RuntimeException('JWKS response missing "keys" array.');
            }

            return $payload;
        });
    }

    /**
     * Sync provider endpoints from discovery into the entity (without persisting).
     */
    public function applyDiscoveryToProvider(IdentityProvider $provider): void
    {
        $d = $this->fetchDiscovery($provider);
        if (isset($d['issuer']) && is_string($d['issuer'])) {
            $provider->setIssuer($d['issuer']);
        }
        if (isset($d['authorization_endpoint']) && is_string($d['authorization_endpoint'])) {
            $provider->setAuthorizationEndpoint($d['authorization_endpoint']);
        }
        if (isset($d['token_endpoint']) && is_string($d['token_endpoint'])) {
            $provider->setTokenEndpoint($d['token_endpoint']);
        }
        if (isset($d['userinfo_endpoint']) && is_string($d['userinfo_endpoint'])) {
            $provider->setUserinfoEndpoint($d['userinfo_endpoint']);
        }
        if (isset($d['jwks_uri']) && is_string($d['jwks_uri'])) {
            $provider->setJwksUri($d['jwks_uri']);
        }
    }
}
