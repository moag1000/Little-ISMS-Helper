<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Own PHPStan rules and extensions read classes only through static reflection
 * (Scope, ReflectionProvider, Type::getObjectClassReflections()). A runtime check
 * like is_subclass_of() autoloads the analysed class; on a PHP older than the code
 * (cloud threads run PHP 8.3, the code uses 8.4 syntax) PHPStan then dies with an
 * internal parse error instead of analysing (B3, scripts/README.md "Cloud-Threads").
 */
final class RulesUseStaticReflectionTest extends TestCase
{
    private const string RUNTIME_REFLECTION =
        '/\b(?:is_subclass_of|is_a|class_exists|interface_exists|enum_exists|class_implements|class_parents)\s*\('
        . '|new\s+\\\\?Reflection(?:Class|Method|Property|Enum)\b/';

    #[Test]
    public function rulesDoNotAutoloadAnalysedClasses(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (
                !str_ends_with($path, '.php') || str_ends_with($path, 'Test.php')
                || str_ends_with($path, 'TestCase.php') || str_contains($path, '/Fixtures/')
            ) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($path)) as $no => $line) {
                $code = preg_replace('#^\s*(?://|\*|/\*).*#', '', $line) ?? '';
                if (preg_match(self::RUNTIME_REFLECTION, $code) === 1) {
                    $offenders[] = substr($path, strlen(__DIR__) + 1) . ':' . ($no + 1) . '  ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, "Runtime reflection in a PHPStan rule — use the Scope/ReflectionProvider:\n"
            . implode("\n", $offenders));
    }
}
