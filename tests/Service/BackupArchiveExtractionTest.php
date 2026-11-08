<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The files of a ZIP backup land in public/uploads/, which every organisation shares and
 * which the application serves from its own origin. Loading a backup (preview, validation,
 * dry run) used to write them before any check; an organisation admin could overwrite
 * another organisation's evidence or plant an .html/.svg file (stored XSS).
 * Now only the restore writes them, after commit — see BackupService::extractArchiveFiles().
 */
final class BackupArchiveExtractionTest extends TestCase
{
    private string $projectDir;
    private BackupService $service;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive extension not available');
        }
        $this->projectDir = sys_get_temp_dir() . '/backup_extract_' . uniqid();
        mkdir($this->projectDir . '/public/uploads/documents', 0755, true);
        $this->service = new BackupService(
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->projectDir)) {
            $this->remove($this->projectDir);
        }
    }

    #[Test]
    public function loadingABackupWritesNoFile(): void
    {
        $zip = $this->archive(['files/documents/new.pdf' => '%PDF new']);

        $backup = $this->service->loadBackupFromFile($zip);

        self::assertArrayHasKey('data', $backup);
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/documents/new.pdf');
    }

    #[Test]
    public function internalMetadataFromTheFileIsDropped(): void
    {
        $zip = $this->archive([], ['_extracted_file_count' => 99, '_archive_path' => '/etc/passwd']);

        $metadata = $this->service->loadBackupFromFile($zip)['metadata'];

        self::assertArrayNotHasKey('_extracted_file_count', $metadata);
        self::assertArrayNotHasKey('_archive_path', $metadata);
    }

    #[Test]
    public function organisationRestoreWritesOnlyNewAllowedFiles(): void
    {
        $foreign = $this->projectDir . '/public/uploads/documents/foreign.pdf';
        file_put_contents($foreign, '%PDF evidence of another organisation');
        $zip = $this->archive([
            'files/documents/foreign.pdf' => '%PDF overwritten',
            'files/documents/new.pdf' => '%PDF new',
            'files/documents/xss.html' => '<script>alert(1)</script>',
            'files/tenant_logos/logo.svg' => '<svg onload="alert(1)"/>',
            'files/documents/x.js' => 'alert(1)',
            'files/other/elsewhere.pdf' => '%PDF',
            'files/documents/../escape.pdf' => '%PDF',
        ]);

        $warnings = [];
        $written = $this->service->extractArchiveFiles($zip, [7], $warnings);

        self::assertSame(1, $written);
        self::assertStringEqualsFile($foreign, '%PDF evidence of another organisation', 'an existing file is never replaced');
        self::assertFileExists($this->projectDir . '/public/uploads/documents/new.pdf');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/documents/xss.html');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/tenant_logos/logo.svg');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/documents/x.js');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/other/elsewhere.pdf');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/escape.pdf');
        self::assertCount(2, $warnings, 'skipped and kept files are reported');
    }

    #[Test]
    public function instanceRestoreReplacesExistingFiles(): void
    {
        $existing = $this->projectDir . '/public/uploads/documents/policy.pdf';
        file_put_contents($existing, '%PDF old');
        $zip = $this->archive(['files/documents/policy.pdf' => '%PDF restored']);

        self::assertSame(1, $this->service->extractArchiveFiles($zip, []));
        self::assertStringEqualsFile($existing, '%PDF restored');
    }

    /**
     * @param array<string, string> $files
     * @param array<string, mixed>  $metadata
     */
    private function archive(array $files, array $metadata = []): string
    {
        $path = $this->projectDir . '/backup_test.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('backup.json', (string) json_encode([
            'metadata' => ['version' => '2.0', 'files_included' => $files !== []] + $metadata,
            'data' => [],
        ]));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
