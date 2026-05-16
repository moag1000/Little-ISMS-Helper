<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_flash_domain.py.
 */
final class CheckFlashDomainTest extends TestCase
{
    private string $script;
    private string $baseline;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_flash_domain.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/flash_domain.txt';
        if (!is_file($this->script)) {
            $this->markTestSkipped('check_flash_domain.py not present');
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
            "Repo regressed against flash-domain baseline.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
        );
    }

    #[Test]
    public function bareSingleArgTransIsFlagged(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController {
                public function index(): void {
                    $this->addFlash('success', $this->translator->trans('foo.bar'));
                }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(1, $exit, $stdout);
            $this->assertStringContainsString("foo.bar", $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function threeArgTransIsAccepted(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController2 {
                public function index(): void {
                    $this->addFlash('success', $this->translator->trans('foo.bar', [], 'asset'));
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
    public function annotationSilencesViolation(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController3 {
                public function index(): void {
                    // @flash-domain-fallback-ok: validation domain only
                    $this->addFlash('error', $this->translator->trans('validation.invalid'));
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
        $tmp = tempnam(sys_get_temp_dir(), 'flashfixture_') . 'Controller.php';
        file_put_contents($tmp, $content);
        return $tmp;
    }
}
