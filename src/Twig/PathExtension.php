<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Strips the absolute project-directory prefix from filesystem paths so that
 * status pages (monitoring, health-check) do not leak the deployment user's
 * home directory or hostname to end-users / screenshots.
 *
 * Twig: `{{ path|path_relative }}`
 *
 * `/Users/alice/Nextcloud/www/foo/var/cache/dev`
 *   → `var/cache/dev`
 *
 * Paths that do not begin with the project dir are returned unchanged.
 */
final class PathExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('path_relative', $this->pathRelative(...)),
        ];
    }

    public function pathRelative(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $prefix = rtrim($this->projectDir, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }
        if ($path === rtrim($this->projectDir, '/')) {
            return '.';
        }

        return $path;
    }
}
