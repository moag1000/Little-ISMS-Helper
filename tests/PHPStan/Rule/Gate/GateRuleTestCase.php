<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Testing\RuleTestCase;

/**
 * Base of the gate rule tests: the expectation lives in the fixture. A line that must be reported ends with
 * `// expect: <identifier>` (several separated by commas); every other line must stay silent. So a fixture reads
 * as the mutation table of its gate: each mutation of the T3 counter-check is one marked line (red), each
 * accepted spelling one unmarked line (green).
 *
 * @template TRule of \PHPStan\Rules\Rule
 * @extends RuleTestCase<TRule>
 */
abstract class GateRuleTestCase extends RuleTestCase
{
    private static bool $autoloader = false;

    /**
     * The fixtures are analysed one file at a time. PHPStan finds other classes through the registered autoloaders,
     * so the fixture classes get one: a parent class in the same file, `self::CONST` and `implementsInterface()`
     * would read as unknown otherwise.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$autoloader) {
            return;
        }
        self::$autoloader = true;
        $map = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/Fixtures', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $code = (string) file_get_contents((string) $file);
            if (preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $ns) === 1
                && preg_match_all('/^(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+(\w+)/m', $code, $classes) > 0) {
                foreach ($classes[1] as $class) {
                    $map[$ns[1] . '\\' . $class] = (string) $file;
                }
            }
        }
        spl_autoload_register(static function (string $class) use ($map): void {
            if (isset($map[$class])) {
                require_once $map[$class];
            }
        });
    }

    protected function assertFixture(string $fixture): void
    {
        $file = __DIR__ . '/Fixtures/' . $fixture;
        self::assertFileExists($file);

        $expected = [];
        foreach ((array) file($file) as $i => $line) {
            if (preg_match('~//\s*expect:\s*([\w.,\s]+)$~', (string) $line, $m) === 1) {
                foreach (array_filter(array_map('trim', explode(',', $m[1]))) as $identifier) {
                    $expected[] = ($i + 1) . ' ' . $identifier;
                }
            }
        }

        $actual = [];
        $messages = [];
        foreach ($this->gatherAnalyserErrors([$file]) as $error) {
            $key = $error->getLine() . ' ' . $error->getIdentifier();
            $actual[] = $key;
            $messages[] = $key . ': ' . $error->getMessage();
        }
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual, "Findings of {$fixture}:\n" . implode("\n", $messages));
    }
}
