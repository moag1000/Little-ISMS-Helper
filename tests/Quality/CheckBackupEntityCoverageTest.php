<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_backup_entity_coverage.py —
 * Gate 43 (CI gate that enforces every entity under src/Entity/ is either
 * in BackupService::PRODUCTIVE_ENTITIES or in BackupService::EXCLUDED_FROM_BACKUP).
 *
 * Runs the analyzer against the live repo and asserts a clean exit. Also
 * pins the --help contract so future flag-renames stay deliberate.
 */
final class CheckBackupEntityCoverageTest extends TestCase
{
    private string $script;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_backup_entity_coverage.py';
        if (!is_file($this->script)) {
            $this->markTestSkipped('check_backup_entity_coverage.py not present');
        }
    }

    #[Test]
    public function repoIsCleanAgainstLiveCheck(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript(['--quiet']);
        $this->assertSame(
            0,
            $exit,
            "Gate 43 failed — at least one src/Entity/*.php class is not categorized in\n"
            . "BackupService::PRODUCTIVE_ENTITIES or EXCLUDED_FROM_BACKUP.\n"
            . "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
    }

    #[Test]
    public function scriptParsesHelpFlag(): void
    {
        [$exit, $stdout] = $this->runScript(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--quiet', $stdout);
        $this->assertStringContainsString('PRODUCTIVE_ENTITIES', $stdout);
        $this->assertStringContainsString('EXCLUDED_FROM_BACKUP', $stdout);
    }

    #[Test]
    public function scriptDetectsUncategorizedEntity(): void
    {
        // Build a sandbox project root containing two entities, only one of
        // which is listed in the BackupService fixture. The script must
        // exit 1 and name the uncategorized entity in its error output.
        $tempDir = sys_get_temp_dir() . '/gate43_test_' . uniqid();
        mkdir($tempDir . '/src/Entity', 0o755, true);
        mkdir($tempDir . '/src/Service', 0o755, true);
        mkdir($tempDir . '/scripts/quality', 0o755, true);

        try {
            copy($this->script, $tempDir . '/scripts/quality/check_backup_entity_coverage.py');
            chmod($tempDir . '/scripts/quality/check_backup_entity_coverage.py', 0o755);

            // Two entity files — one of them is NOT in any list.
            file_put_contents(
                $tempDir . '/src/Entity/SampleEntity.php',
                "<?php\nnamespace App\\Entity;\nclass SampleEntity {}\n",
            );
            file_put_contents(
                $tempDir . '/src/Entity/OrphanedEntity.php',
                "<?php\nnamespace App\\Entity;\nclass OrphanedEntity {}\n",
            );

            // A minimal BackupService that lists SampleEntity only.
            file_put_contents(
                $tempDir . '/src/Service/BackupService.php',
                <<<'PHP'
                <?php
                namespace App\Service;
                class BackupService
                {
                    private const array PRODUCTIVE_ENTITIES = [
                        'SampleEntity',
                    ];
                    private const array EXCLUDED_FROM_BACKUP = [
                    ];
                }
                PHP
            );

            $cmd = [
                'python3',
                $tempDir . '/scripts/quality/check_backup_entity_coverage.py',
            ];
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $tempDir,
            );
            if (!is_resource($proc)) {
                $this->fail('Could not spawn analyzer in sandbox');
            }
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            $this->assertSame(
                1,
                $exit,
                "Gate 43 must FAIL when an entity is uncategorized.\n"
                . "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );
            $this->assertStringContainsString(
                'OrphanedEntity',
                $stderr,
                'Failure output should name the uncategorized entity.',
            );
        } finally {
            $this->removeDirectory($tempDir);
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
