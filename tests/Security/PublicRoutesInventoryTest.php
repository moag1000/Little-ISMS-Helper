<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\PublicPathRateLimitSubscriber;
use App\Security\SetupAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * S25: the routes an anonymous visitor can reach, pinned. A route is anonymous when the
 * first matching access_control rule is PUBLIC_ACCESS (or no rule matches) and its
 * controller carries no #[IsGranted] that demands a login. Every such route is listed
 * below with the guard that replaces the login; a new one fails until someone writes down
 * why it may be public, a removed one fails until its line is deleted.
 *
 * Also checked: each public route is throttled (PublicPathRateLimitSubscriber or its own
 * named throttle), each PUBLIC_ACCESS rule in security.yaml matches at least one route
 * (a rule that matches nothing hid that /de/saml/* sat behind the login), and an unknown
 * bearer token answers 404.
 */
final class PublicRoutesInventoryTest extends WebTestCase
{
    /** Route name => what guards it instead of a login. */
    private const array PUBLIC_ROUTES = [
        'app_home' => 'redirects to login, dashboard or welcome; renders nothing',
        'app_login' => 'login form; firewall login_throttling',
        'app_logout' => 'logout; CSRF token (S9)',
        'oauth_azure_connect' => 'starts Azure OAuth; state in session',
        'oauth_azure_check' => 'Azure OAuth callback; AzureOAuthAuthenticator, SsoClaimPolicy',
        'saml_login' => 'starts SAML; own request id (SamlMessageGuard)',
        'saml_acs' => 'SAML response; signature + own request id (SamlMessageGuard)',
        'saml_metadata' => 'SP metadata the IdP reads; public by design',
        'saml_sls' => 'SAML logout; signed request',
        'app_sso_start' => 'OIDC start; state/PKCE/nonce in session',
        'app_sso_callback' => 'OIDC callback; GenericOidcAuthenticator, SsoClaimPolicy',
        'app_health' => 'probe; answers "ok" only',
        'app_readyz' => 'probe; db/queue ok or fail, no detail',
        'app_metrics' => 'probe; memory gauges, no version',
        'app_public_posture' => 'Trust Center; 192-bit token, hash_equals, opt-in per organisation',
        'public_supplier_questionnaire_show' => 'questionnaire; 256-bit token, response window',
        'public_supplier_questionnaire_submit' => 'questionnaire; token + CSRF per token',
        'app_mcp_endpoint' => 'MCP; bearer token only, McpController answers 401 itself',
        'app_mcp_endpoint_other' => 'MCP; answers 405 (no SSE)',
        'app_quick_fix_index' => 'Quick-Fix; QuickFixGuard',
        'app_quick_fix_apply' => 'Quick-Fix; QuickFixGuard + CSRF',
        'app_quick_fix_reconcile' => 'Quick-Fix; QuickFixGuard + CSRF',
        'app_quick_fix_force_mark_executed' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_mark_all_phantom_diff' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_repair_orphans' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_repair_mismatches' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_repair_duplicates' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_repair_all' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_force_schema_update' => 'Quick-Fix; QuickFixGuard::mayRunPrivileged + CSRF',
        'app_quick_fix_job_status' => 'Quick-Fix job polling; quick-fix jobs only',
        'app_quick_fix_job_progress' => 'Quick-Fix progress page; quick-fix jobs only',
        'app_quick_fix_workflows_sync' => 'Quick-Fix; QuickFixGuard + CSRF',
    ];

    /**
     * Public routes the PublicPathRateLimitSubscriber does not cover, with their own throttle.
     */
    private const array OWN_THROTTLE = [
        'app_home' => 'redirect only',
        'app_login' => 'firewall login_throttling (5 per user+IP, 25 per IP in 15 min)',
        'app_logout' => 'ends the own session only',
        'app_mcp_endpoint' => 'ApiRateLimitSubscriber (api limiter) + api_auth_failure',
        'app_mcp_endpoint_other' => 'answers 405 without work',
    ];

    #[Test]
    public function anonymousRoutesAreExactlyTheListedOnes(): void
    {
        $public = $this->anonymousRoutes();

        $setup = array_filter($public, static fn (array $r): bool => $r['setup']);
        $others = array_diff_key($public, $setup);

        $unlisted = array_diff_key($others, self::PUBLIC_ROUTES);
        $stale = array_diff_key(self::PUBLIC_ROUTES, $others);
        self::assertSame(
            [],
            array_map(static fn (array $r): string => $r['path'], $unlisted),
            "Routes reachable without login that PUBLIC_ROUTES does not list (add them with their guard, or gate them):\n"
            . implode("\n", array_map(static fn (string $n, array $r): string => sprintf('%s  %s %s', $n, $r['method'], $r['path']), array_keys($unlisted), $unlisted)),
        );
        self::assertSame([], array_keys($stale), 'PUBLIC_ROUTES lists routes that are no longer anonymous or gone — delete the lines.');
        self::assertNotEmpty($setup, 'the setup wizard (SETUP_ACCESS) was not found — is the discovery broken?');
    }

    #[Test]
    public function everyAnonymousRouteIsThrottled(): void
    {
        $missing = [];
        foreach ($this->anonymousRoutes() as $name => $route) {
            if ($route['setup'] || isset(self::OWN_THROTTLE[$name])) {
                continue;
            }
            if (PublicPathRateLimitSubscriber::limiterFor($route['path']) === null) {
                $missing[] = $name . '  ' . $route['path'];
            }
        }
        self::assertSame([], $missing, 'Anonymous routes without a rate limit (add a pattern to PublicPathRateLimitSubscriber):');
        self::assertSame([], array_keys(array_diff_key(self::OWN_THROTTLE, self::PUBLIC_ROUTES)), 'OWN_THROTTLE names a non-public route.');
    }

    #[Test]
    public function everyPublicAccessRuleMatchesARoute(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/security.yaml');
        $paths = array_map(fn (Route $route): string => $this->samplePath($route), $this->routes());

        $dead = [];
        foreach ($config['security']['access_control'] as $rule) {
            if (!in_array('PUBLIC_ACCESS', (array) ($rule['roles'] ?? []), true)) {
                continue;
            }
            $pattern = '{' . $rule['path'] . '}';
            if (array_filter($paths, static fn (string $p): bool => preg_match($pattern, $p) === 1) === []) {
                $dead[] = $rule['path'];
            }
        }
        self::assertSame([], $dead, 'PUBLIC_ACCESS rules that match no route (the real route has another path — fix the rule):');
    }

    #[Test]
    public function unknownBearerTokensAnswerNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/trust/' . bin2hex(random_bytes(24)));
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/supplier-questionnaire/' . bin2hex(random_bytes(32)));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, array{path: string, method: string, setup: bool}>
     */
    private function anonymousRoutes(): array
    {
        self::bootKernel();
        /** @var AccessMapInterface $accessMap */
        $accessMap = self::getContainer()->get('security.access_map');

        $public = [];
        foreach ($this->routes() as $name => $route) {
            $path = $this->samplePath($route);
            $method = $route->getMethods()[0] ?? 'GET';
            [$roles] = $accessMap->getPatterns(Request::create($path, $method));
            if ($roles !== null && !in_array('PUBLIC_ACCESS', $roles, true)) {
                continue;
            }
            $gate = $this->controllerGate($route);
            if ($gate === 'login') {
                continue;
            }
            $public[$name] = ['path' => $path, 'method' => $method, 'setup' => $gate === 'setup'];
        }
        ksort($public);

        return $public;
    }

    /**
     * @return array<string, Route>
     */
    private function routes(): array
    {
        self::bootKernel();
        $routes = [];
        foreach (self::getContainer()->get('router')->getRouteCollection()->all() as $name => $route) {
            // Profiler, toolbar and error previews sit in the dev firewall (security: false).
            if (preg_match('#^/_#', $route->getPath()) === 1) {
                continue;
            }
            $routes[$name] = $route;
        }

        return $routes;
    }

    private function samplePath(Route $route): string
    {
        $path = str_replace('{_locale}', 'de', $route->getPath());

        return (string) preg_replace('/\{[^}]+\}/', '1', $path);
    }

    /**
     * 'login' when the controller demands a login (role or authenticated), 'setup' when it
     * is the setup wizard (anonymous until setup is complete), null otherwise. Voter
     * attributes and expressions decide per request and count as "no login demanded".
     */
    private function controllerGate(Route $route): ?string
    {
        $controller = $route->getDefault('_controller');
        if (!is_string($controller) || !str_contains($controller, '::')) {
            return null;
        }
        [$class, $method] = explode('::', $controller, 2);
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF)];
        }

        $gate = null;
        foreach ($attributes as $attribute) {
            $value = $attribute->newInstance()->attribute;
            if (is_string($value) && (str_starts_with($value, 'ROLE_') || str_starts_with($value, 'IS_AUTHENTICATED'))) {
                return 'login';
            }
            if ($value === SetupAccessChecker::ACCESS) {
                $gate = 'setup';
            }
        }

        return $gate;
    }
}
