<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_module_gating.py.
 *
 * Wraps the analyzer in a subprocess and asserts:
 *  1. With the baseline applied, the repo is clean (regression guard for
 *     newly-added regulatory fields without a module-gate).
 *  2. On a synthetic violation snippet, the analyzer exits 1.
 *  3. Annotated violations (// @no-module-gate-required) are silenced.
 */
final class CheckModuleGatingTest extends TestCase
{
    private string $script;
    private string $baseline;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_module_gating.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/module_gating.txt';

        if (!is_file($this->script)) {
            $this->markTestSkipped('check_module_gating.py not present');
        }
    }

    #[Test]
    public function repoIsCleanAgainstBaseline(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript([
            '--baseline', $this->baseline, '--quiet',
        ]);
        $this->assertSame(
            0,
            $exit,
            "Repo regressed against module-gating baseline.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
        );
    }

    #[Test]
    public function syntheticUngatedFieldIsFlagged(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _Synth1Type {
                public function buildForm($builder, $options): void {
                    $builder->add('doraRelevance', TextType::class);
                }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(1, $exit, "Expected exit 1 for ungated DORA field, got {$exit}.\n{$stdout}");
            $this->assertStringContainsString('doraRelevance', $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function gatedFieldIsAccepted(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _Synth2Type {
                public function buildForm($builder, $options): void {
                    if ($this->isModuleActive('nis2_dora')) {
                        $builder->add('doraRelevance', TextType::class);
                    }
                }
            }
            PHP);
        try {
            [$exit] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function annotationSuppressesViolation(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _Synth3Type {
                public function buildForm($builder, $options): void {
                    // @no-module-gate-required: abstract base class
                    $builder->add('doraRelevance', TextType::class);
                }
            }
            PHP);
        try {
            [$exit] = $this->runScript(['--paths', $tmp]);
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
        $tmp = tempnam(sys_get_temp_dir(), 'gatingfixture_') . 'Type.php';
        file_put_contents($tmp, $content);
        return $tmp;
    }
}
