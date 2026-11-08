<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Http\UserUrlHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * W3: every outgoing request to a URL a person typed in goes through
 * UserUrlHttpClient (no LAN, loopback, link-local or metadata address — checked
 * on the resolved IP — and no redirects by default).
 */
final class OutboundUserUrlClientTest extends TestCase
{
    /**
     * Classes that may hold a raw HttpClientInterface, with the reason.
     * A new entry needs a URL nobody can type in, or its own guard.
     */
    private const array RAW_CLIENT_ALLOWED = [
        'src/Http/UserUrlHttpClient.php' => 'the guard itself',
        'src/Service/Ai/OpenAiCompatibleClient.php' => 'wraps external endpoints in NoPrivateNetworkHttpClient; local models are an explicit instance setting',
        'src/Service/Vulnerability/EuvdConnector.php' => 'fixed base URL from a container parameter (app.euvd.base_url)',
    ];

    /** @return iterable<string, array{string}> */
    public static function internalTargets(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'private 10/8' => ['https://10.0.0.5/.well-known/openid-configuration'];
        yield 'private 192.168/16' => ['https://192.168.1.1/hook'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
        yield 'IPv6 unique local' => ['https://[fd00::1]/'];
    }

    #[Test]
    #[DataProvider('internalTargets')]
    public function internalAddressesAreRefused(string $url): void
    {
        $client = new UserUrlHttpClient(new MockHttpClient(new MockResponse('{}')));

        $this->expectException(TransportExceptionInterface::class);
        $client->request('GET', $url)->getStatusCode();
    }

    #[Test]
    #[DataProvider('internalTargets')]
    public function theGuzzleBridgeRefusesInternalAddressesToo(string $url): void
    {
        $client = new UserUrlHttpClient(new MockHttpClient(new MockResponse('{}')));

        $this->expectException(\Throwable::class);
        $client->guzzle()->request('GET', $url);
    }

    #[Test]
    public function redirectsAreOffByDefault(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;
            return new MockResponse('{}');
        });
        (new UserUrlHttpClient($mock))->request('GET', 'https://93.184.215.14/')->getStatusCode();

        self::assertSame(0, $seen['max_redirects'] ?? null);
    }

    #[Test]
    public function noOtherClassHoldsARawHttpClient(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            $code = (string) file_get_contents($file->getPathname());
            $raw = preg_match('/\bHttpClientInterface\s+\$|HttpClient::create\(|new\s+\\\\?(Symfony\\\\Component\\\\HttpClient\\\\)?(Curl|Native|Amp)HttpClient\(|new\s+\\\\?GuzzleHttp\\\\Client\(/', $code) === 1;
            if ($raw && !isset(self::RAW_CLIENT_ALLOWED[$rel])) {
                $offenders[] = $rel;
            }
        }

        self::assertSame([], $offenders, 'Outgoing requests to configurable URLs must use App\Http\UserUrlHttpClient.');
    }

    #[Test]
    public function everyOAuthProviderGetsTheGuardedClient(): void
    {
        $root = dirname(__DIR__, 2);
        $code = (string) file_get_contents($root . '/src/Service/Sso/OidcAuthenticationFlow.php');

        self::assertStringContainsString("'httpClient' => \$this->userUrlClient->guzzle()", $code);
        self::assertSame(1, substr_count($code, 'new GenericProvider('), 'A second provider construction must pass the guarded client as well.');
    }
}
