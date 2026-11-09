<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Admin\TrustCenterAdminController;
use App\Http\PublicUrlGenerator;
use App\Service\AssetQrCodeService;
use App\Service\DocumentApprovalService;
use App\Service\IncidentEscalationWorkflowService;
use App\Service\Reminder\ObligationReminderService;
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
        'src/Controller/ApiTokenController.php' => 'shows the MCP URL to the person on their own page',
        'src/Controller/McpController.php' => 'answer to the bearer client itself; Origin check',
        'src/Controller/WorkflowController.php' => 'same-origin check of the Referer, builds no link',
        'src/Mcp/Tool/ProposeDraftTool.php' => 'answer to the bearer client itself',
        'src/Mcp/Tool/ComplianceFulfillmentTool.php' => 'answer to the bearer client itself',
        'src/Mcp/Tool/PendingSuggestionsTool.php' => 'answer to the bearer client itself',
        'src/Mcp/Tool/MyDayTool.php' => 'answer to the bearer client itself',
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

    #[Test]
    public function noOtherCodeBuildsAbsoluteUrlsFromTheRequest(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $code = (string) file_get_contents($file->getPathname());
            $buildsAbsolute = preg_match('/\bABSOLUTE_URL\b|getSchemeAndHttpHost\s*\(|getUriForPath\s*\(|NETWORK_PATH/', $code) === 1;
            if (!$buildsAbsolute || isset(self::REQUEST_BOUND[$relative])) {
                continue;
            }
            if (!str_contains($code, 'PublicUrlGenerator')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, "Absolute URLs from the request host. Use App\\Http\\PublicUrlGenerator (or add the file to REQUEST_BOUND with a reason):\n" . implode("\n", $offenders));
    }

    #[Test]
    public function mailTemplatesTakeLinksFromThePublicGenerator(): void
    {
        $root = \dirname(__DIR__, 2) . '/templates/emails';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $twig = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(absolute_url|url)\s*\(/', $twig) === 1) {
                $offenders[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Mail templates build links with url()/absolute_url() from the request host; pass them in from PublicUrlGenerator.');
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
