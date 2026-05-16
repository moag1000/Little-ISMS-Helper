<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_freetext_legacy.py.
 */
final class CheckFreetextLegacyTest extends TestCase
{
    private string $script;
    private string $baseline;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_freetext_legacy.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/freetext_legacy.txt';
        if (!is_file($this->script)) {
            $this->markTestSkipped('check_freetext_legacy.py not present');
        }
    }

    #[Test]
    public function repoIsCleanAgainstBaselineInStrictMode(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript([
            '--baseline', $this->baseline, '--strict', '--quiet',
        ]);
        $this->assertSame(
            0,
            $exit,
            "Repo regressed against freetext-legacy baseline.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
        );
    }

    #[Test]
    public function defaultModeIsWarnOnly(): void
    {
        // Synthetic violation should produce findings but exit 0 without --strict.
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _SynthFreetextWarnType {
                public function buildForm($builder, $options): void {
                    $builder->add('leadAuditor', TextType::class);
                }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(0, $exit, "warn-only mode should exit 0; got {$exit}\n{$stdout}");
            $this->assertStringContainsString('leadAuditor', $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function strictModeExits1OnViolation(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _SynthFreetextStrictType {
                public function buildForm($builder, $options): void {
                    $builder->add('leadAuditor', TextType::class);
                }
            }
            PHP);
        try {
            [$exit] = $this->runScript(['--paths', $tmp, '--strict']);
            $this->assertSame(1, $exit);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function annotationSilencesFinding(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _SynthFreetextAnnotatedType {
                public function buildForm($builder, $options): void {
                    // @legacy-freetext: display-only legacy column
                    $builder->add('leadAuditor', TextType::class);
                }
            }
            PHP);
        try {
            [$exit] = $this->runScript(['--paths', $tmp, '--strict']);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function runScript(array $args): array
    {
        $cmd = array_merge(['python3', $this->script], $args);
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($proc)) {
            $this->fail('Could not spawn analyzer');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit, $stdout, $stderr];
    }

    private function writeFixture(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'freetextfixture_') . 'Type.php';
        file_put_contents($tmp, $content);
        return $tmp;
    }
}
