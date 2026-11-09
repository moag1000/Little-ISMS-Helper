<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The files of a ZIP backup land in shared upload folders (documents in var/uploads/documents
 * since S7, outside the web root; logos in public/uploads/). Loading a backup (preview, validation,
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
        mkdir($this->projectDir . '/var/uploads/documents', 0750, true);
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
        self::assertFileDoesNotExist($this->projectDir . '/var/uploads/documents/new.pdf');
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
        $foreign = $this->projectDir . '/var/uploads/documents/foreign.pdf';
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
        self::assertFileExists($this->projectDir . '/var/uploads/documents/new.pdf');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/documents/new.pdf', 'evidence never lands in the web root');
        self::assertFileDoesNotExist($this->projectDir . '/var/uploads/documents/xss.html');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/tenant_logos/logo.svg');
        self::assertFileDoesNotExist($this->projectDir . '/var/uploads/documents/x.js');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/other/elsewhere.pdf');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/escape.pdf');
        self::assertCount(2, $warnings, 'skipped and kept files are reported');
    }

    #[Test]
    public function instanceRestoreReplacesExistingFiles(): void
    {
        $existing = $this->projectDir . '/var/uploads/documents/policy.pdf';
        file_put_contents($existing, '%PDF old');
        $zip = $this->archive(['files/documents/policy.pdf' => '%PDF restored']);

        self::assertSame(1, $this->service->extractArchiveFiles($zip, []));
        self::assertStringEqualsFile($existing, '%PDF restored');
    }

    #[Test]
    public function trainingMaterialsTravelInTheArchive(): void
    {
        // S11: materials (ISO 27001 7.2/7.3 evidence) were missing from ZIP backups.
        mkdir($this->projectDir . '/var/uploads/training-materials', 0750, true);
        $material = $this->projectDir . '/var/uploads/training-materials/kurs.pdf';
        file_put_contents($material, '%PDF material');

        $collect = new \ReflectionMethod(BackupService::class, 'collectFileReferences');
        $refs = $collect->invoke($this->service, [
            'Training' => [['id' => 1, 'materialFiles' => [['filename' => 'kurs.pdf', 'originalName' => 'Kurs.pdf']]]],
        ]);

        self::assertSame(['training_materials/kurs.pdf' => $material], $refs);
    }

    #[Test]
    public function restoredTrainingMaterialsLandOutsideTheWebRoot(): void
    {
        $zip = $this->archive(['files/training_materials/kurs.pdf' => '%PDF material']);

        self::assertSame(1, $this->service->extractArchiveFiles($zip, [7]));
        self::assertFileExists($this->projectDir . '/var/uploads/training-materials/kurs.pdf');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/training_materials/kurs.pdf');
    }

    /**
     * T2: dropping `document_versions` from ARCHIVE_PROTECTED_FOLDERS stayed green — a restored
     * version then landed in public/uploads/document_versions/, inside the web root.
     */
    #[Test]
    public function restoredDocumentVersionsLandOutsideTheWebRoot(): void
    {
        $zip = $this->archive(['files/document_versions/policy_v2.pdf' => '%PDF version 2']);

        self::assertSame(1, $this->service->extractArchiveFiles($zip, [7]));
        self::assertFileExists($this->projectDir . '/var/uploads/documents/policy_v2.pdf');
        self::assertFileDoesNotExist($this->projectDir . '/public/uploads/document_versions/policy_v2.pdf');
    }

    #[Test]
    public function everyArchiveFolderButLogosIsRestoredOutsideTheWebRoot(): void
    {
        $class = new \ReflectionClass(BackupService::class);
        $folders = $class->getConstant('ARCHIVE_FILE_FOLDERS');
        $protected = $class->getConstant('ARCHIVE_PROTECTED_FOLDERS');

        // Tenant logos are shown on the login page and in mails, so they stay public (ProtectedUploads).
        self::assertSame(
            array_values(array_diff($folders, ['tenant_logos'])),
            array_keys($protected),
            'An archive folder with evidence must map to a ProtectedUploads area.',
        );
    }

    #[Test]
    public function everyProtectedUploadAreaIsBackedUp(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/BackupService.php');
        $carried = array_column(BackupService::FILE_LIST_FIELDS, 2);
        foreach (\App\Service\Upload\ProtectedUploads::AREAS as $area) {
            self::assertTrue(
                in_array($area, $carried, true) || ($area === \App\Service\Upload\ProtectedUploads::DOCUMENTS && str_contains($source, "'Document'        => 'filePath'")),
                sprintf('Upload area %s has no file reference in BackupService — its files would be missing from ZIP backups.', $area),
            );
        }
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
