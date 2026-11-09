<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\LikePattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** S24: a search for `__` or `%` matches those characters, not every record. */
final class LikePatternTest extends TestCase
{
    #[Test]
    public function wildcardsInTheInputAreMatchedLiterally(): void
    {
        self::assertSame('%\_\_%', LikePattern::contains('__'));
        self::assertSame('%50\%%', LikePattern::contains('50%'));
        self::assertSame('%a\\\\b%', LikePattern::contains('a\\b'));
        self::assertSame('%Müller%', LikePattern::contains('Müller'));
    }

    #[Test]
    public function nonTextInputBecomesAnEmptyPattern(): void
    {
        self::assertSame('%42%', LikePattern::contains(42));
        self::assertSame('%%', LikePattern::contains(null));
        self::assertSame('%%', LikePattern::contains(['x']));
    }

    /** No LIKE pattern from input is built by hand in src/ (the helper is the one way). */
    #[Test]
    public function noHandBuiltContainsPatternIsLeft(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/setParameter\([^;]*?[\'"]%[\'"]\s*\.\s*\$/', $code, $m) > 0) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders, 'Build LIKE patterns from input with LikePattern::contains().');
    }
}
