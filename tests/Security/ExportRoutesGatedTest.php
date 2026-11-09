<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every export or download action names who may run it: an #[IsGranted] (role, voter
 * attribute or Expression) on the action or its controller. Before S16/B1 the
 * ComplianceExportController exports relied on the catch-all access_control rule alone,
 * so one changed rule would have opened twelve export endpoints at once.
 *
 * Public token downloads (no login by design) are listed with their reason.
 */
final class ExportRoutesGatedTest extends TestCase
{
    /** "Class::method" => reason. */
    private const PUBLIC_BY_DESIGN = [];

    #[Test]
    public function everyExportOrDownloadActionCarriesIsGranted(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        $checked = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Controller', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), \strlen($root . '/src/Controller/')));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isTrait()) {
                continue;
            }
            $classGated = $reflection->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $route = $attribute->newInstance();
                    $paths = (array) $route->getPath();
                    $haystack = $route->getName() . ' ' . implode(' ', $paths) . ' ' . $method->getName();
                    if (preg_match('/export|download/i', $haystack) !== 1) {
                        continue;
                    }
                    ++$checked;
                    $key = $class . '::' . $method->getName();
                    $gated = $classGated || $method->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
                    if (!$gated && !isset(self::PUBLIC_BY_DESIGN[$key])) {
                        $offenders[$key] = $key . ' (' . implode(', ', $paths) . ')';
                    }
                }
            }
        }

        self::assertGreaterThan(20, $checked, 'the scan found (almost) no export routes — is the discovery broken?');
        self::assertSame([], array_values($offenders), "Export/download actions without #[IsGranted]:\n" . implode("\n", $offenders));
    }
}
