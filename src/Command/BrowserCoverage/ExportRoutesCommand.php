<?php

declare(strict_types=1);

namespace App\Command\BrowserCoverage;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Exports the Symfony route table to a JSON file the browser-coverage L1
 * smoke spec consumes. Only GET routes without required path-parameters
 * (other than `_locale`) are included — those are the candidates for a
 * naked navigation smoke-test.
 *
 * Routes prefixed `_`, `api_`, `app_logout`, or matching skip-patterns
 * are filtered out; admin-only routes are tagged so the spec can decide
 * per-persona whether to expect 200 or 403.
 */
#[AsCommand(
    name: 'app:browser-coverage:export-routes',
    description: 'Export GET-able routes to JSON for the L1 browser-smoke spec',
)]
class ExportRoutesCommand
{
    /** @var list<string> route-name fragments that should never be smoke-tested */
    private const SKIP_NAME_PATTERNS = [
        '_profiler',
        '_wdt',
        '_preview_error',
        'api_',
        'app_logout',
        '_doctrine_migrations',
        '_health',
        '_assets',
    ];

    /** @var list<string> path fragments that always require complex setup */
    private const SKIP_PATH_PATTERNS = [
        '/_fragment',
        '/_wdt',
        '/_profiler',
        '/api/',
    ];

    public function __construct(
        private readonly RouterInterface $router,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $collection = $this->router->getRouteCollection();
        $rows = [];
        $skipped = 0;

        foreach ($collection->all() as $name => $route) {
            $methods = $route->getMethods();
            if ($methods !== [] && !in_array('GET', $methods, true)) {
                $skipped++;
                continue;
            }

            if ($this->matchesAny($name, self::SKIP_NAME_PATTERNS)) {
                $skipped++;
                continue;
            }

            $path = $route->getPath();
            if ($this->matchesAny($path, self::SKIP_PATH_PATTERNS)) {
                $skipped++;
                continue;
            }

            $rendered = $this->renderPath($route);
            if ($rendered === null) {
                // Required path-parameter without a sample default — not smoke-safe.
                $skipped++;
                continue;
            }

            $rows[] = [
                'name'     => $name,
                'path'     => $rendered,
                'raw'      => $path,
                'methods'  => $methods === [] ? ['GET'] : $methods,
                'admin'    => $this->isAdminRoute($name, $path),
                'category' => $this->categorise($name, $path),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        $outputPath = $this->projectDir . '/var/browser-coverage/routes.json';
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0o775, true);
        }
        file_put_contents(
            $outputPath,
            json_encode(
                ['generated_at' => date(DATE_ATOM), 'count' => count($rows), 'routes' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        $io->success(sprintf('Exported %d routes to %s (skipped %d)', count($rows), $outputPath, $skipped));

        return Command::SUCCESS;
    }

    /**
     * Render a route path with `_locale=de` (and any sample-default parameters).
     * Returns null when the route requires path-parameters we have no default
     * for — those routes are not navigation-smoke candidates.
     */
    private function renderPath(Route $route): ?string
    {
        $path = $route->getPath();
        $defaults = $route->getDefaults();

        // Always force `de` locale when the route accepts one.
        if (str_contains($path, '{_locale}')) {
            $path = str_replace('{_locale}', 'de', $path);
        }

        // Substitute any other parameter that has a default value.
        if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $path, $matches) === false) {
            return null;
        }
        foreach ($matches[1] as $param) {
            if (!array_key_exists($param, $defaults)) {
                return null;
            }
            $path = str_replace('{' . $param . '}', (string) $defaults[$param], $path);
        }

        return $path;
    }

    private function isAdminRoute(string $name, string $path): bool
    {
        return str_contains($path, '/admin/') || str_contains($name, 'admin_');
    }

    /**
     * Coarse categorisation so the report can group routes per ISMS area.
     */
    private function categorise(string $name, string $path): string
    {
        return match (true) {
            str_contains($path, '/admin/')              => 'admin',
            str_contains($path, '/setup')               => 'setup',
            str_contains($path, '/dashboard')           => 'dashboard',
            str_contains($path, '/risk')                => 'risk',
            str_contains($path, '/asset')               => 'asset',
            str_contains($path, '/incident')            => 'incident',
            str_contains($path, '/control') ||
            str_contains($path, '/soa')                 => 'control',
            str_contains($path, '/audit')               => 'audit',
            str_contains($path, '/compliance')          => 'compliance',
            str_contains($path, '/document') ||
            str_contains($path, '/policy')              => 'document',
            str_contains($path, '/report') ||
            str_contains($path, '/analytics')           => 'reporting',
            str_contains($path, '/supplier')            => 'supplier',
            str_contains($path, '/training')            => 'training',
            str_contains($path, '/bcm') ||
            str_contains($path, '/business-continuity') => 'bcm',
            str_contains($path, '/data-breach') ||
            str_contains($path, '/dpia') ||
            str_contains($path, '/processing-activity') => 'privacy',
            str_contains($path, '/dashboards/')         => 'persona-dashboard',
            default                                     => 'other',
        };
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
