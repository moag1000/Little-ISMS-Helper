<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Service\Sso\OidcDiscoveryService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Unit tests for OidcDiscoveryService.
 *
 * Uses MockHttpClient to avoid real HTTP calls.
 */
#[AllowMockObjectsWithoutExpectations]
final class OidcDiscoveryServiceTest extends TestCase
{
    private function makeService(array $responses): OidcDiscoveryService
    {
        $http = new MockHttpClient($responses);

        // Cache: always miss (call through to factory)
        $cache = new class implements CacheInterface {
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                $item = new class implements ItemInterface {
                    public function expiresAfter(int|\DateInterval|null $time): static { return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function getKey(): string { return 'test'; }
                    public function get(): mixed { return null; }
                    public function isHit(): bool { return false; }
                    public function set(mixed $value): static { return $this; }
                    public function tag(string|iterable $tags): static { return $this; }
                    public function getMetadata(): array { return []; }
                };
                return $callback($item);
            }

            public function delete(string $key): bool { return true; }
        };

        return new OidcDiscoveryService($http, $cache, new NullLogger());
    }

    #[Test]
    public function fetchDiscoveryReturnsDocumentOnSuccess(): void
    {
        $doc = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/auth',
            'token_endpoint' => 'https://example.com/token',
            'jwks_uri' => 'https://example.com/jwks',
        ];

        $service = $this->makeService([new MockResponse(json_encode($doc))]);

        $provider = (new IdentityProvider())->setDiscoveryUrl('https://example.com/.well-known/openid-configuration');
        $result = $service->fetchDiscovery($provider);

        self::assertSame('https://example.com', $result['issuer']);
        self::assertSame('https://example.com/auth', $result['authorization_endpoint']);
    }

    #[Test]
    public function fetchDiscoveryThrowsOnHttpError(): void
    {
        $service = $this->makeService([new MockResponse('Not Found', ['http_code' => 404])]);
        $provider = (new IdentityProvider())->setDiscoveryUrl('https://example.com/.well-known/openid-configuration');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/404/');
        $service->fetchDiscovery($provider);
    }

    #[Test]
    public function fetchDiscoveryThrowsWhenNoUrlOrIssuer(): void
    {
        $service = $this->makeService([]);
        $provider = new IdentityProvider();

        $this->expectException(\RuntimeException::class);
        $service->fetchDiscovery($provider);
    }

    #[Test]
    public function applyDiscoveryToProviderSetsEndpoints(): void
    {
        $doc = [
            'issuer' => 'https://example.com',
            'authorization_endpoint' => 'https://example.com/auth',
            'token_endpoint' => 'https://example.com/token',
            'userinfo_endpoint' => 'https://example.com/userinfo',
            'jwks_uri' => 'https://example.com/jwks',
        ];

        $service = $this->makeService([new MockResponse(json_encode($doc))]);
        $provider = (new IdentityProvider())->setDiscoveryUrl('https://example.com/.well-known/openid-configuration');

        $service->applyDiscoveryToProvider($provider);

        self::assertSame('https://example.com', $provider->getIssuer());
        self::assertSame('https://example.com/auth', $provider->getAuthorizationEndpoint());
        self::assertSame('https://example.com/token', $provider->getTokenEndpoint());
        self::assertSame('https://example.com/userinfo', $provider->getUserinfoEndpoint());
        self::assertSame('https://example.com/jwks', $provider->getJwksUri());
    }

    #[Test]
    public function fetchDiscoveryUsesIssuerUrlWhenDiscoveryUrlNotSet(): void
    {
        $doc = ['issuer' => 'https://example.com', 'jwks_uri' => 'https://example.com/jwks'];
        $service = $this->makeService([new MockResponse(json_encode($doc))]);

        $provider = (new IdentityProvider())->setIssuer('https://example.com');
        $result = $service->fetchDiscovery($provider);

        self::assertSame('https://example.com', $result['issuer']);
    }

    #[Test]
    public function fetchDiscoveryThrowsOnInvalidJson(): void
    {
        $service = $this->makeService([new MockResponse('not-json')]);
        $provider = (new IdentityProvider())->setDiscoveryUrl('https://example.com/.well-known/openid-configuration');

        $this->expectException(\Throwable::class);
        $service->fetchDiscovery($provider);
    }
}
