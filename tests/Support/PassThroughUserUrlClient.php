<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\UserUrlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * UserUrlHttpClient over a MockHttpClient without the network guard, for unit
 * tests of the services that use it. Keeps the redirect default so a test
 * still sees `max_redirects: 0`. The guard itself is tested in
 * tests/Security/OutboundUserUrlClientTest.
 */
final class PassThroughUserUrlClient extends UserUrlHttpClient
{
    /** @var list<array<string, mixed>> */
    public array $seenOptions = [];

    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['max_redirects'] ??= 0;
        $this->seenOptions[] = $options;

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }
}
