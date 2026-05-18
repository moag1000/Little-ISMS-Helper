<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_status_enum_yaml_parity.py —
 * the Gate 42 CI gate that enforces 1:1 parity between Symfony Workflow
 * YAML `places:` and the corresponding `App\Enum\<X>Status` enum cases.
 *
 * The script has no per-path mode (it scans a hard-coded directory), so
 * this test simply pins the repo-vs-baseline contract: when run against
 * the checked-in baseline, the gate exits 0.
 */
final class CheckStatusEnumYamlParityTest extends TestCase
{
    private string $script;
    private string $baseline;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_status_enum_yaml_parity.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/status_enum_yaml_parity.txt';
        if (!is_file($this->script)) {
            $this->markTestSkipped('check_status_enum_yaml_parity.py not present');
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
            "Repo regressed against status-enum-yaml-parity baseline.\n"
            . "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
    }

    #[Test]
    public function scriptParsesHelpFlag(): void
    {
        [$exit, $stdout] = $this->runScript(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--baseline', $stdout);
        $this->assertStringContainsString('--write-baseline', $stdout);
    }

    #[Test]
    public function baselineFileExists(): void
    {
        $this->assertFileExists(
            $this->baseline,
            'Gate 42 baseline file must exist (created via --write-baseline).',
        );
        $contents = (string) file_get_contents($this->baseline);
        $this->assertStringContainsString(
            '# check_status_enum_yaml_parity.py baseline',
            $contents,
            'Baseline must include the header comment for traceability.',
        );
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
}
