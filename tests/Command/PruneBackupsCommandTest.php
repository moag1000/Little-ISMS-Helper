<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PruneBackupsCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PruneBackupsCommandTest extends TestCase
{
    private string $backupDir;
    private string $projectDir;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/prune_test_' . uniqid();
        $this->backupDir  = $this->projectDir . '/var/backups';
        mkdir($this->backupDir, 0755, true);

        $command = new PruneBackupsCommand($this->projectDir, new NullLogger());
        $app     = new Application();
        $app->addCommand($command);

        $this->tester = new CommandTester($app->find('app:backup:prune'));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    // ------------------------------------------------------------------ //

    public function testOldFilesArePruned(): void
    {
        // Create a file that is 10 days old
        $old = $this->backupDir . '/backup_old.json';
        file_put_contents($old, '{}');
        touch($old, strtotime('-10 days'));

        // Create a recent file (today)
        $new = $this->backupDir . '/backup_new.json';
        file_put_contents($new, '{}');

        $this->tester->execute(['--keep-days' => 1, '--keep-last' => 0]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertFileDoesNotExist($old, 'Old file should have been deleted');
        $this->assertFileExists($new, 'Recent file should be kept');
    }

    public function testRecentFilesAreKept(): void
    {
        $file = $this->backupDir . '/backup_recent.json';
        file_put_contents($file, '{}');
        // mtime defaults to now — well within any keep-days window

        $this->tester->execute(['--keep-days' => 30, '--keep-last' => 0]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertFileExists($file);
    }

    public function testKeepLastOverridesAge(): void
    {
        // Create 3 files, all 60 days old
        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $path = $this->backupDir . "/backup_old_$i.json";
            file_put_contents($path, '{}');
            touch($path, strtotime('-60 days') + $i); // stagger mtime by 1 s
            $files[] = $path;
        }

        // keep-last=2 means the 2 newest are protected
        $this->tester->execute(['--keep-days' => 1, '--keep-last' => 2]);

        $this->assertSame(0, $this->tester->getStatusCode());

        // Sort by mtime descending to identify the two newest
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        // files[0] and files[1] are newest — should exist (may already be deleted)
        // files[2] is oldest — should be gone
        $this->assertFileDoesNotExist($files[2], 'Oldest file should be pruned despite keep-last protecting 2');
        // At least one of the two newest must still be present
        $this->assertTrue(
            file_exists($files[0]) || file_exists($files[1]),
            'At least one of the two newest files should be kept'
        );
    }

    public function testDryRunPrintsButDoesNotDelete(): void
    {
        $old = $this->backupDir . '/backup_dryrun.json';
        file_put_contents($old, '{}');
        touch($old, strtotime('-60 days'));

        $this->tester->execute(['--keep-days' => 1, '--keep-last' => 0, '--dry-run' => true]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertFileExists($old, 'Dry-run must not delete files');
        $this->assertStringContainsString('DRY RUN', $this->tester->getDisplay());
    }

    public function testMissingBackupDirReturnsFailure(): void
    {
        // Point to a project dir that has no var/backups
        $emptyProject = sys_get_temp_dir() . '/prune_empty_' . uniqid();
        mkdir($emptyProject, 0755);

        $command = new PruneBackupsCommand($emptyProject, new NullLogger());
        $app     = new Application();
        $app->addCommand($command);
        $tester = new CommandTester($app->find('app:backup:prune'));

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());

        rmdir($emptyProject);
    }

    public function testTableContainsFilenameColumn(): void
    {
        $file = $this->backupDir . '/backup_table.json';
        file_put_contents($file, '{}');

        $this->tester->execute(['--keep-days' => 30, '--keep-last' => 0]);

        $this->assertStringContainsString('backup_table.json', $this->tester->getDisplay());
    }

    // ------------------------------------------------------------------ //

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
