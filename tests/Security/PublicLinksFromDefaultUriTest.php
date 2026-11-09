<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Admin\TrustCenterAdminController;
use App\Http\PublicUrlGenerator;
use App\Service\AssetQrCodeService;
use App\Service\DocumentApprovalService;
use App\Service\IncidentEscalationWorkflowService;
use App\Service\Reminder\ObligationReminderService;
use App\Tests\Support\PhpSourceScanner;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * S16: links that leave the browser session (mails, supplier questionnaire, Trust
 * Center, QR labels) come from DEFAULT_URI, never from the Host header. Before, a
 * request with `Host: attacker.example` that raised an incident escalation sent every
 * manager a genuine mail whose button pointed at the attacker.
 */
final class PublicLinksFromDefaultUriTest extends KernelTestCase
{
    /**
     * Files that may build absolute URLs from the current request, with the reason.
     * Everything else that builds one must go through PublicUrlGenerator.
     */
    private const REQUEST_BOUND = [
        'src/Http/PublicUrlGenerator.php' => 'the generator itself',
        'src/Security/AzureSamlAuthenticator.php' => 'SSO, hardened in S9 (DEFAULT_URI via SamlAuthFactory)',
        'src/Security/SamlAuthFactory.php' => 'SSO, hardened in S9 (DEFAULT_URI)',
        'src/Security/SafeRedirectTarget.php' => 'compares a redirect target with the request host, builds no link',
        'src/Controller/Admin/PublicAddressController.php' => 'admin page that proposes the address the admin is using',
        'src/Controller/ApiTokenController.php' => 'shows the MCP URL to the person on their own page',
        'src/Controller/McpController.php' => 'answer to the bearer client itself; Origin check',
        'src/Controller/WorkflowController.php' => 'same-origin check of the Referer, builds no link',
        'src/Mcp/Tool/ProposeDraftTool.php' => 'answer to the bearer client itself',
        'src/Mcp/Tool/ComplianceFulfillmentTool.php' => 'answer to the bearer client itself',
        'src/Mcp/Tool/PendingSuggestionsTool.php' => 'answer to the bearer client itself',
        'src/Mcp/Tool/MyDayTool.php' => 'answer to the bearer client itself',
    ];

    /** Twig access to the request's scheme/host (`app.request.schemeAndHttpHost`, `.host`, `.httpHost`, `getHost()`). */
    private const REQUEST_HOST_IN_TWIG = '(?:request|app\\.request)\\s*(?:\\.|\\?\\.)\\s*(?:get)?(?:schemeAndHttpHost|httpHost|host|scheme|uriForPath)\\b';

    /** Templates that may read the request host, with the reason. */
    private const TEMPLATE_REQUEST_BOUND = [
        'templates/monitoring/health.html.twig' => 'admin page that shows the address the admin is currently using, next to the configured one',
    ];

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    #[Test]
    public function linksUseDefaultUriEvenWhenTheRequestNamesAnotherHost(): void
    {
        $router = $this->routerFor('attacker.example');
        $urls = new PublicUrlGenerator($router, 'https://isms.example.org');

        self::assertSame('https://isms.example.org/de/incident/7', $urls->generate('incident', ['id' => 7]));
        self::assertSame('attacker.example', $router->getContext()->getHost(), 'the request context is restored');
    }

    #[Test]
    public function defaultUriWithSubdirectoryAndPortIsKept(): void
    {
        $urls = new PublicUrlGenerator($this->routerFor('attacker.example'), 'http://intranet.local:8080/isms/');

        self::assertSame('http://intranet.local:8080/isms/de/incident/7', $urls->generate('incident', ['id' => 7]));
    }

    #[Test]
    public function placeholderDefaultUriNeverFallsBackToAnUntrustedHost(): void
    {
        Request::setTrustedHosts([]);
        $urls = new PublicUrlGenerator($this->routerFor('attacker.example'), 'http://localhost');

        self::assertStringStartsWith('http://localhost/', $urls->generate('incident', ['id' => 7]));
        self::assertContains('monitoring.public_address.default_uri_missing', $urls->configurationIssues());
        self::assertContains('monitoring.public_address.trusted_hosts_missing', $urls->configurationIssues());
    }

    #[Test]
    public function placeholderDefaultUriUsesTheRequestHostOnlyWhenTrustedHostsCheckedIt(): void
    {
        Request::setTrustedHosts(['^isms\.example\.org$']);
        $urls = new PublicUrlGenerator($this->routerFor('isms.example.org'), 'http://localhost');

        self::assertSame('https://isms.example.org/de/incident/7', $urls->generate('incident', ['id' => 7]));
    }

    #[Test]
    public function unanchoredOrMismatchingTrustedHostsAreReported(): void
    {
        Request::setTrustedHosts(['isms.example.org']);
        $issues = (new PublicUrlGenerator($this->routerFor('x'), 'https://other.example.org'))->configurationIssues();

        self::assertContains('monitoring.public_address.trusted_hosts_unanchored', $issues);
        self::assertContains('monitoring.public_address.default_uri_not_trusted', $issues);

        Request::setTrustedHosts(['^isms\.example\.org$', '^localhost$']);
        self::assertSame([], (new PublicUrlGenerator($this->routerFor('x'), 'https://isms.example.org'))->configurationIssues());
    }

    #[Test]
    public function mailAndLinkServicesAreWiredToThePublicGenerator(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $wired = [
            IncidentEscalationWorkflowService::class => 'urlGenerator',
            DocumentApprovalService::class => 'urlGenerator',
            ObligationReminderService::class => 'urls',
            AssetQrCodeService::class => 'urlGenerator',
            TrustCenterAdminController::class => 'urlGenerator',
        ];
        foreach ($wired as $class => $property) {
            $service = $container->get($class);
            $value = (new \ReflectionProperty($class, $property))->getValue($service);
            self::assertInstanceOf(PublicUrlGenerator::class, $value, sprintf('%s builds mail/public links from the request host', $class));
        }
    }

    /**
     * Per call site, not per file: a file that merely names PublicUrlGenerator (use, docblock, an unrelated
     * property) is no longer exempt. A call that builds an absolute URL is fine only when its receiver is a
     * property typed PublicUrlGenerator.
     */
    #[Test]
    public function noOtherCodeBuildsAbsoluteUrlsFromTheRequest(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        foreach (PhpSourceScanner::phpFiles($root . '/src') as $path) {
            $relative = substr($path, \strlen($root) + 1);
            if (isset(self::REQUEST_BOUND[$relative])) {
                continue;
            }
            foreach (self::absoluteUrlCallSites(PhpSourceScanner::parseFile($path)) as $site) {
                $offenders[] = $relative . ':' . $site;
            }
        }

        self::assertSame([], $offenders, "Absolute URLs from the request host. Use App\\Http\\PublicUrlGenerator (or add the file to REQUEST_BOUND with a reason):\n" . implode("\n", $offenders));
    }

    #[Test]
    public function theScannerSeesEveryWayToBuildAnAbsoluteUrl(): void
    {
        $bad = [
            'ABSOLUTE_URL via router' => '$this->router->generate("a", [], UrlGeneratorInterface::ABSOLUTE_URL);',
            'ABSOLUTE_URL on the controller' => '$this->generateUrl("a", [], \\Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface::ABSOLUTE_URL);',
            'literal 0' => '$this->router->generate("a", [], 0);',
            'literal 3 (network path)' => '$this->router->generate("a", [], 3);',
            'named referenceType' => '$this->router->generate("a", referenceType: 0);',
            'getHttpHost' => '$r->getScheme() . "://" . $r->getHttpHost();',
            'getHost' => '$host = $request->getHost();',
            'getSchemeAndHttpHost' => '$request->getSchemeAndHttpHost();',
            'getUriForPath' => '$request->getUriForPath("/x");',
            'nullsafe' => '$request?->getHost();',
            'receiver of another type' => '$this->urls->generate("a", [], UrlGeneratorInterface::ABSOLUTE_URL);',
        ];
        foreach ($bad as $label => $statement) {
            $code = '<?php namespace App\\X; use Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface; '
                . 'use App\\Http\\PublicUrlGenerator; /* PublicUrlGenerator */ '
                . 'final class C { public function __construct(private object $urls) {} public function m($r, $request) { ' . $statement . ' } }';
            self::assertNotSame(
                [],
                self::absoluteUrlCallSites(PhpSourceScanner::parseCode($code)),
                $label . ' must be flagged even though the file names PublicUrlGenerator',
            );
        }

        $good = '<?php namespace App\\X; use Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface; '
            . 'use App\\Http\\PublicUrlGenerator as Public_; '
            . '// $request->getHost() in a comment'."\n"
            . 'final class C { public function __construct(private Public_ $urls) {} '
            . 'public function m() { return $this->urls->generate("a", [], UrlGeneratorInterface::ABSOLUTE_URL); } }';
        self::assertSame([], self::absoluteUrlCallSites(PhpSourceScanner::parseCode($good)), 'a typed PublicUrlGenerator receiver (aliased) is the one way');

        $autowired = '<?php namespace App\\X; use Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface; '
            . 'use Symfony\\Component\\DependencyInjection\\Attribute\\Autowire; use App\\Http\\PublicUrlGenerator; '
            . 'final class C { public function __construct(#[Autowire(service: PublicUrlGenerator::class)] private readonly UrlGeneratorInterface $urlGenerator) {} '
            . 'public function m() { return $this->urlGenerator->generate("a", [], UrlGeneratorInterface::ABSOLUTE_URL); } }';
        self::assertSame([], self::absoluteUrlCallSites(PhpSourceScanner::parseCode($autowired)), 'the autowired public generator counts');
    }

    /**
     * @param list<Node> $ast
     *
     * @return list<string> "line: what"
     */
    private static function absoluteUrlCallSites(array $ast): array
    {
        $sites = [];
        $publicTypes = [];
        // Typed PublicUrlGenerator, or typed UrlGeneratorInterface and wired with #[Autowire(service: PublicUrlGenerator::class)]
        // (mailAndLinkServicesAreWiredToThePublicGenerator() proves the wiring at run time).
        $isPublic = static function (?Node $type, array $attrGroups): bool {
            if ($type instanceof Node\Name && $type->toString() === PublicUrlGenerator::class) {
                return true;
            }
            foreach ($attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($attribute->name->getLast() !== 'Autowire') {
                        continue;
                    }
                    foreach ($attribute->args as $position => $arg) {
                        $isService = $arg->name?->toString() === 'service' || ($arg->name === null && $position === 0);
                        if ($isService && $arg->value instanceof Node\Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
                            && $arg->value->class->toString() === PublicUrlGenerator::class) {
                            return true;
                        }
                    }
                }
            }

            return false;
        };
        foreach (PhpSourceScanner::find($ast, Node\Param::class) as $param) {
            if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name) && $param->flags !== 0
                && $isPublic($param->type, $param->attrGroups)) {
                $publicTypes[$param->var->name] = true;
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Stmt\Property::class) as $property) {
            if ($isPublic($property->type, $property->attrGroups)) {
                foreach ($property->props as $prop) {
                    $publicTypes[$prop->name->toString()] = true;
                }
            }
        }
        $isPublicReceiver = static fn (Node $receiver): bool => $receiver instanceof Node\Expr\PropertyFetch
            && $receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier && isset($publicTypes[$receiver->name->toString()]);

        foreach (PhpSourceScanner::find($ast, Node\Expr\ClassConstFetch::class) as $fetch) {
            if ($fetch->name instanceof Node\Identifier && $fetch->name->toString() === 'NETWORK_PATH') {
                $sites[] = $fetch->getStartLine() . ': NETWORK_PATH';
            }
        }

        $calls = [...PhpSourceScanner::find($ast, Node\Expr\MethodCall::class), ...PhpSourceScanner::find($ast, Node\Expr\NullsafeMethodCall::class)];
        foreach ($calls as $call) {
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }
            $name = $call->name->toString();
            if (\in_array(strtolower($name), ['getschemeandhttphost', 'gethttphost', 'gethost', 'geturiforpath'], true)) {
                $sites[] = $call->getStartLine() . ': ' . $name . '()';
                continue;
            }
            if (!\in_array(strtolower($name), ['generate', 'generateurl'], true)) {
                continue;
            }
            foreach ($call->args as $position => $arg) {
                if (!$arg instanceof Node\Arg) {
                    continue;
                }
                $isReferenceType = ($position === 2 && $arg->name === null) || $arg->name?->toString() === 'referenceType';
                if (!$isReferenceType) {
                    continue;
                }
                $value = $arg->value;
                $absolute = $value instanceof Node\Expr\ClassConstFetch && $value->name instanceof Node\Identifier
                    && $value->name->toString() === 'ABSOLUTE_URL'
                    || $value instanceof Node\Scalar\Int_ && \in_array($value->value, [0, 3], true);
                if ($absolute && !$isPublicReceiver($call->var)) {
                    $sites[] = $call->getStartLine() . ': ' . $name . '() with an absolute reference type on a receiver that is not a PublicUrlGenerator property';
                }
            }
        }

        return $sites;
    }

    #[Test]
    public function mailTemplatesTakeLinksFromThePublicGenerator(): void
    {
        $root = \dirname(__DIR__, 2) . '/templates/emails';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $twig = PhpSourceScanner::twigWithoutComments((string) file_get_contents($file->getPathname()));
            if (preg_match('/\b(absolute_url|url)\s*\(|' . self::REQUEST_HOST_IN_TWIG . '/i', $twig) === 1) {
                $offenders[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Mail templates build links with url()/absolute_url()/app.request host from the request; pass them in from PublicUrlGenerator.');
    }

    #[Test]
    public function noTemplateReadsTheRequestHostToBuildALink(): void
    {
        $root = \dirname(__DIR__, 2) . '/templates';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = 'templates/' . substr($file->getPathname(), \strlen($root) + 1);
            $twig = PhpSourceScanner::twigWithoutComments((string) file_get_contents($file->getPathname()));
            if (preg_match('/' . self::REQUEST_HOST_IN_TWIG . '/i', $twig) === 1 && !isset(self::TEMPLATE_REQUEST_BOUND[$relative])) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Templates read the request host (app.request.schemeAndHttpHost, host, httpHost). Use PublicUrlGenerator or list the template in TEMPLATE_REQUEST_BOUND with a reason.');
    }

    private function routerFor(string $requestHost): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('incident', new Route('/{_locale}/incident/{id}'));
        $context = new RequestContext('', 'GET', $requestHost, 'https');
        $context->setParameter('_locale', 'de');

        return new UrlGenerator($routes, $context);
    }
}
