<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the DSR controller used flash keys prefixed
 * `data_subject_request.flash.*`, but the translation file nests everything
 * under the `dsr:` top key (→ `dsr.flash.*`). The wrong prefix never resolved,
 * so users saw raw keys like "data_subject_request.flash.completed".
 */
final class DataSubjectRequestFlashKeyTest extends TestCase
{
    private static function source(): string
    {
        $f = __DIR__ . '/../../src/Controller/DataSubjectRequestController.php';
        self::assertFileExists($f);
        $s = file_get_contents($f);
        self::assertIsString($s);
        return $s;
    }

    #[Test]
    public function noFlashCallUsesTheUnresolvableDataSubjectRequestPrefix(): void
    {
        self::assertStringNotContainsString(
            'data_subject_request.flash.',
            self::source(),
            'Flash keys must use the dsr.flash.* prefix that matches the translation file nesting.',
        );
    }

    #[Test]
    public function flashCallsUseDsrPrefix(): void
    {
        self::assertStringContainsString("'dsr.flash.completed'", self::source());
    }
}
