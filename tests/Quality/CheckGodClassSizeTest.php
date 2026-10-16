<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * scripts/quality/check_god_class_size.py (Gate 25) — the baseline is a
 * one-way ratchet: files may not grow past it, stale entries fail with
 * --strict-shrink, and the baseline itself may not grow against its base.
 */
final class CheckGodClassSizeTest extends TestCase
{
    private string $root;
    private string $script;
    private string $baseline;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_god_class_size.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/god_class_size.txt';
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function repoMatchesTheBaselineExactly(): void
    {
        [$exit, $out] = $this->run_(['--baseline', $this->baseline, '--strict-shrink']);
        self::assertSame(0, $exit, $out);
    }

    #[Test]
    public function aShrunkFileWithAnUnloweredEntryFailsUnderStrictShrink(): void
    {
        $inflated = $this->baselineWith(static fn(int $loc): int => $loc + 10);

        [$lenientExit, $lenientOut] = $this->run_(['--baseline', $inflated]);
        self::assertSame(0, $lenientExit, $lenientOut);
        self::assertStringContainsString('NOTE', $lenientOut);

        [$strictExit, $strictOut] = $this->run_(['--baseline', $inflated, '--strict-shrink']);
        self::assertSame(1, $strictExit, $strictOut);
        self::assertStringContainsString('--write-baseline', $strictOut);
    }

    #[Test]
    public function aFileGrowingPastItsEntryFails(): void
    {
        $tight = $this->baselineWith(static fn(int $loc): int => $loc - 1);

        [$exit, $out] = $this->run_(['--baseline', $tight]);
        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('GREW', $out);
    }

    #[Test]
    public function theRatchetRejectsARaisedBaselineEntry(): void
    {
        $raised = $this->baselineWith(static fn(int $loc): int => $loc + 10);

        [$exit, $out] = $this->run_(['--baseline', $raised, '--ratchet-base-file', $this->baseline]);
        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('baseline RAISED', $out);
    }

    #[Test]
    public function theRatchetRejectsAnAddedBaselineEntry(): void
    {
        $lines = file($this->baseline, FILE_IGNORE_NEW_LINES) ?: [];
        $entries = array_values(array_filter($lines, static fn(string $l): bool => $l !== '' && $l[0] !== '#'));
        self::assertNotEmpty($entries);
        // The base lacks the first entry → the current baseline "added" it.
        $base = $this->tmp(implode("\n", array_diff($lines, [$entries[0]])) . "\n");

        [$exit, $out] = $this->run_(['--baseline', $this->baseline, '--ratchet-base-file', $base]);
        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('baseline entry ADDED', $out);
    }

    #[Test]
    public function theRatchetIsSkippedForABaseWithoutFormatHeader(): void
    {
        $legacy = $this->tmp("# old baseline\n");

        [$exit, $out] = $this->run_(['--baseline', $this->baseline, '--ratchet-base-file', $legacy]);
        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('bootstrap', $out);
    }

    /** Copy of the baseline with every LOC value mapped through $map. */
    private function baselineWith(callable $map): string
    {
        $text = (string) file_get_contents($this->baseline);

        return $this->tmp((string) preg_replace_callback(
            '/LOC=(\d+)/',
            static fn(array $m): string => 'LOC=' . $map((int) $m[1]),
            $text,
        ));
    }

    private function tmp(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'godclass');
        file_put_contents($file, $content);
        $this->tmpFiles[] = $file;

        return $file;
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function run_(array $args): array
    {
        $proc = proc_open(
            array_merge(['python3', $this->script], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($proc)) {
            self::fail('Could not spawn check_god_class_size.py');
        }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }
}
