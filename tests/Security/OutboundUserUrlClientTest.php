<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Http\UserUrlHttpClient;
use App\Tests\Support\PhpSourceScanner;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Psr\Http\Client\NetworkExceptionInterface;
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
        // Found by T (wave 7), closed by S11: the library's own Guzzle client is replaced.
        'src/Service/WebPushService.php' => 'builds WebPush with new Psr18Client(UserUrlHttpClient); endpoints checked first by PushEndpointPolicy (tests/Security/WebPushEndpointTest)',
    ];

    /**
     * Ways to open a connection besides Symfony's HttpClientInterface: other HTTP
     * clients (Guzzle, PSR-18, vendor SDKs that bring their own, SOAP), curl and
     * sockets, and stream wrappers on URLs.
     */
    private const string RAW_CLIENT = '/\\bHttpClientInterface\s+\$|HttpClient::create\('
        . '|new\s+\\\\?(Symfony\\\\Component\\\\HttpClient\\\\)?(Curl|Native|Amp)HttpClient\('
        . '|new\s+\\\\?(GuzzleHttp\\\\)?Client\(\s*\[|new\s+\\\\?GuzzleHttp\\\\Client\('
        . '|\\bPsr18Client\(|\\bClientInterface\s+\$(?=\w*[cC]lient)|Psr\\\\Http\\\\Client\\\\ClientInterface'
        . '|new\s+\\\\?(Minishlink\\\\WebPush\\\\)?WebPush\(|new\s+\\\\?SoapClient\('
        . '|\\bcurl_init\(|\\bcurl_multi_init\(|\\bfsockopen\(|\\bstream_socket_client\('
        . '|\\b(file_get_contents|fopen|get_headers)\\(\\s*[\'"]https?:/';

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

        try {
            $client->guzzle()->request('GET', $url);
            self::fail('the Guzzle bridge reached ' . $url);
        } catch (NetworkExceptionInterface $e) {
            // The guard's refusal, not any failure on the way (before T: any \Throwable passed).
            self::assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
        }
    }

    /** Positive control: a public address goes through the bridge, so the refusal above is the guard's. */
    #[Test]
    public function theGuzzleBridgeLetsPublicAddressesThrough(): void
    {
        $client = new UserUrlHttpClient(new MockHttpClient(new MockResponse('{"ok":true}')));

        self::assertSame(200, $client->guzzle()->request('GET', 'https://93.184.215.14/')->getStatusCode());
    }

    /** The scan itself: each way to open a connection is recognised. */
    #[Test]
    public function theScanRecognisesEveryKindOfClient(): void
    {
        foreach ([
            'public function __construct(private HttpClientInterface $http) {}',
            '$c = HttpClient::create();',
            '$c = new \\GuzzleHttp\\Client([]);',
            '$c = new Psr18Client();',
            'public function __construct(private \\Psr\\Http\\Client\\ClientInterface $httpClient) {}',
            '$push = new WebPush($auth);',
            '$soap = new SoapClient($wsdl);',
            '$ch = curl_init($url);',
            '$fp = fsockopen($host, 443);',
            '$body = file_get_contents(\'https://example.org/feed\');',
        ] as $code) {
            self::assertSame(1, preg_match(self::RAW_CLIENT, $code), $code);
        }
        self::assertSame(0, preg_match(self::RAW_CLIENT, '$data = file_get_contents($path);'));
    }

    /**
     * The parsed scan: aliases (`use …HttpClientInterface as Http`), union/nullable types, Guzzle built with a
     * variable (`new Client($config)`) and stream functions on an address that is not a literal. A plain
     * `file_get_contents($path)` on a local path is fine; the address check goes by what the argument is
     * called or how it starts, because ~70 local reads in src/ pass a variable.
     */
    #[Test]
    public function theParsedScanRecognisesEveryKindOfClient(): void
    {
        $bad = [
            'aliased interface' => "use Symfony\\Contracts\\HttpClient\\HttpClientInterface as Http;\nfinal class C { public function __construct(private Http \$c) {} }",
            'nullable' => "use Symfony\\Contracts\\HttpClient\\HttpClientInterface;\nfinal class C { private ?HttpClientInterface \$c = null; }",
            'union' => "use Symfony\\Contracts\\HttpClient\\HttpClientInterface;\nfinal class C { public function m(HttpClientInterface|null \$c) {} }",
            'guzzle with a variable' => "use GuzzleHttp\\Client;\n\$c = new Client(\$config);",
            'guzzle aliased' => "use GuzzleHttp\\Client as G;\n\$c = new G();",
            'psr client' => "use Psr\\Http\\Client\\ClientInterface as Psr;\nfinal class C { public function __construct(private Psr \$c) {} }",
            'stream function, url-like getter' => '$b = file_get_contents($webhook->getUrl());',
            'stream function, url variable' => "\$h = fopen(\$url, 'r');",
            'stream function, endpoint property' => '$b = file_get_contents($this->endpoint);',
            'stream function, http prefix' => "\$b = file_get_contents('https://' . \$host . '/feed');",
            'stream function, double quotes' => '$b = file_get_contents("https://example.org/x");',
            'static factory' => "use Symfony\\Component\\HttpClient\\HttpClient;\n\$c = HttpClient::create();",
        ];
        foreach ($bad as $label => $code) {
            self::assertNotSame([], self::rawClientHits("<?php\n" . $code, $label), $label . ' must be flagged');
        }
        foreach ([
            '$b = file_get_contents($path);',
            '$h = fopen($this->projectDir . "/var/x", "r");',
            '// new Client($config); fopen($url, "r")',
            "use GuzzleHttp\\Psr7\\Utils;\n\$s = Utils::streamFor(\$x);",
        ] as $code) {
            self::assertSame([], self::rawClientHits("<?php\n" . $code, 'ok'), $code);
        }
    }

    /** @return list<string> what the code uses to open a connection by itself */
    private static function rawClientHits(string $code, string $label): array
    {
        $ast = PhpSourceScanner::parseCode($code, $label);
        $hits = [];
        $interface = 'Symfony\\Contracts\\HttpClient\\HttpClientInterface';
        $always = [
            $interface => 'HttpClientInterface',
            'Psr\\Http\\Client\\ClientInterface' => 'PSR-18 client',
        ];
        foreach (PhpSourceScanner::find($ast, Node\Name::class) as $name) {
            $full = ltrim($name->toString(), '\\');
            // Types, `use` lines and `new` all carry the resolved name; an alias is resolved by the parser.
            if (isset($always[$full])) {
                $hits[] = $always[$full];
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Expr\New_::class) as $new) {
            if ($new->class instanceof Node\Name) {
                $full = ltrim($new->class->toString(), '\\');
                if (\in_array($full, ['GuzzleHttp\\Client', 'SoapClient', 'Minishlink\\WebPush\\WebPush', 'Symfony\\Component\\HttpClient\\Psr18Client'], true)
                    || preg_match('/^Symfony\\\\Component\\\\HttpClient\\\\(Curl|Native|Amp)HttpClient$/', $full) === 1) {
                    $hits[] = 'new ' . $full;
                }
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Expr\StaticCall::class) as $call) {
            if ($call->class instanceof Node\Name && ltrim($call->class->toString(), '\\') === 'Symfony\\Component\\HttpClient\\HttpClient') {
                $hits[] = 'HttpClient::create()';
            }
        }
        $functions = ['file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'get_headers', 'simplexml_load_file', 'getimagesize', 'fsockopen', 'stream_socket_client'];
        foreach (PhpSourceScanner::find($ast, Node\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Node\Name || !\in_array(strtolower($call->name->getLast()), $functions, true)) {
                continue;
            }
            $argument = $call->args[0] ?? null;
            if ($argument instanceof Node\Arg && self::looksLikeAnAddress($argument->value)) {
                $hits[] = $call->name->toString() . '() on an address';
            }
        }

        return $hits;
    }

    /** `$url`, `$x->getUrl()`, `$this->endpoint`, `'http' . $host`: what a person typed in or an http(s) literal. */
    private static function looksLikeAnAddress(Node\Expr $argument): bool
    {
        $constant = PhpSourceScanner::constantString($argument);
        if ($constant !== null) {
            return preg_match('#^https?:#i', $constant) === 1;
        }
        if ($argument instanceof Node\Expr\BinaryOp\Concat) {
            $first = $argument;
            while ($first instanceof Node\Expr\BinaryOp\Concat) {
                $first = $first->left;
            }
            $prefix = PhpSourceScanner::constantString($first);

            return $prefix !== null && preg_match('#^https?:#i', $prefix) === 1;
        }
        $name = match (true) {
            $argument instanceof Node\Expr\Variable && \is_string($argument->name) => $argument->name,
            $argument instanceof Node\Expr\PropertyFetch && $argument->name instanceof Node\Identifier => $argument->name->toString(),
            ($argument instanceof Node\Expr\MethodCall || $argument instanceof Node\Expr\NullsafeMethodCall) && $argument->name instanceof Node\Identifier => $argument->name->toString(),
            default => '',
        };

        return preg_match('/url|uri|endpoint|webhook|href|link|host|address/i', $name) === 1;
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
            $raw = preg_match(self::RAW_CLIENT, $code) === 1 || self::rawClientHits($code, $rel) !== [];
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
