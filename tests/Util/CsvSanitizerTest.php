<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\CsvSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CsvSanitizer — OWASP CSV formula-injection prevention.
 */
final class CsvSanitizerTest extends TestCase
{
    #[Test]
    public function sanitize_returns_non_strings_unchanged(): void
    {
        self::assertSame(42, CsvSanitizer::sanitize(42));
        self::assertSame(3.14, CsvSanitizer::sanitize(3.14));
        self::assertNull(CsvSanitizer::sanitize(null));
        self::assertTrue(CsvSanitizer::sanitize(true));
        $arr = ['a', 'b'];
        self::assertSame($arr, CsvSanitizer::sanitize($arr));
    }

    #[Test]
    public function sanitize_returns_safe_strings_unchanged(): void
    {
        self::assertSame('hello world', CsvSanitizer::sanitize('hello world'));
        self::assertSame('Normal value', CsvSanitizer::sanitize('Normal value'));
        self::assertSame('', CsvSanitizer::sanitize(''));
        self::assertSame('123', CsvSanitizer::sanitize('123'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dangerousPrefixProvider(): array
    {
        return [
            'equals sign'   => ['=SUM(A1:A10)', "'=SUM(A1:A10)"],
            'plus sign'     => ['+cmd|"calc.exe"', "'+cmd|\"calc.exe\""],
            'minus sign'    => ['-2+3', "'-2+3"],
            'at sign'       => ['@SUM(A1)', "'@SUM(A1)"],
            'tab prefix'    => ["\tformula", "'\tformula"],
            'CR prefix'     => ["\rformula", "'\rformula"],
        ];
    }

    #[Test]
    #[DataProvider('dangerousPrefixProvider')]
    public function sanitize_prefixes_dangerous_values_with_quote(string $input, string $expected): void
    {
        self::assertSame($expected, CsvSanitizer::sanitize($input));
    }

    #[Test]
    public function sanitize_does_not_double_quote_already_safe_value(): void
    {
        // A value starting with ' is already safe — must not be double-prefixed
        $input = "'=already_safe";
        $result = CsvSanitizer::sanitize($input);
        // ' is not in the dangerous prefix list, so it passes through unchanged
        self::assertSame("'=already_safe", $result);
    }
}
