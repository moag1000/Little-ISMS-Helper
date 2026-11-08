<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The one HTTP client for URLs a person typed in (webhook, OIDC discovery and
 * JWKS, geocoder endpoint, …).
 *
 * - Every connection — also after DNS resolution and on every redirect hop —
 *   is refused for loopback, private, link-local and metadata addresses
 *   (NoPrivateNetworkHttpClient checks the resolved IP, so DNS rebinding
 *   cannot reach the LAN). IPv6 ranges are covered as well.
 * - Redirects are off by default (`max_redirects: 0`): a 302 to another host
 *   would otherwise carry the request past the URL check.
 *
 * `NoInternalIp` on the form stays as the friendly early error; this client is
 * the enforcement. Gate: tests/Security/OutboundUserUrlClientTest.
 */
class UserUrlHttpClient implements HttpClientInterface
{
    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->client = new NoPrivateNetworkHttpClient($httpClient);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['max_redirects'] ??= 0;

        return $this->client->request($method, $url, $options);
    }

    /**
     * The same guard for libraries that insist on Guzzle (league/oauth2-client
     * for the OIDC token and userinfo calls): Guzzle hands every request to this
     * client via PSR-18, redirects stay off.
     */
    public function guzzle(): \GuzzleHttp\ClientInterface
    {
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $psr18 = new \Symfony\Component\HttpClient\Psr18Client($this, $factory, $factory);
        $handler = static fn (\Psr\Http\Message\RequestInterface $request): \GuzzleHttp\Promise\PromiseInterface
            => \GuzzleHttp\Promise\Create::promiseFor($psr18->sendRequest($request));

        return new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create($handler),
            'allow_redirects' => false,
        ]);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }
}
