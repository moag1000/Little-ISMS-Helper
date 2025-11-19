<?php

namespace App\Tests\Service;

use App\Service\FileUploadSecurityService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploadSecurityServiceTest extends TestCase
{
    private FileUploadSecurityService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->service = new FileUploadSecurityService();
        $this->tempDir = sys_get_temp_dir() . '/file_upload_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testValidateUploadedFileAcceptsValidPdf(): void
    {
        $file = $this->createTestFile('test.pdf', $this->getPdfContent());

        $this->service->validateUploadedFile($file);

        // If no exception is thrown, validation passed
        $this->assertTrue(true);
    }

    public function testValidateUploadedFileAcceptsValidJpeg(): void
    {
        $file = $this->createTestFile('test.jpg', $this->getJpegContent());

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileAcceptsValidPng(): void
    {
        $file = $this->createTestFile('test.png', $this->getPngContent());

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileRejectsInvalidExtension(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File extension "exe" is not allowed');

        $file = $this->createTestFile('malware.exe', 'fake content');

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileRejectsOversizedFile(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File size exceeds maximum allowed size');

        // Create a file larger than 10MB
        $largeContent = str_repeat('A', 11 * 1024 * 1024);
        $file = $this->createTestFile('large.pdf', $largeContent);

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileRejectsMismatchedMimeType(): void
    {
        $this->expectException(FileException::class);

        // Create a file with .pdf extension but actually text content
        $file = $this->createTestFile('fake.pdf', 'This is just text, not a PDF');

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileRejectsMismatchedMagicBytes(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File signature does not match expected type');

        // Create a PDF file with wrong magic bytes (using JPEG magic bytes instead)
        $fakeContent = hex2bin('ffd8ffe0') . ' fake pdf content';
        $file = $this->createTestFile('fake.pdf', $fakeContent);

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileAcceptsDocxFile(): void
    {
        // DOCX files are ZIP-based, so we create a minimal ZIP
        $file = $this->createTestFile('document.docx', $this->getZipContent());

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileAcceptsXlsxFile(): void
    {
        $file = $this->createTestFile('spreadsheet.xlsx', $this->getZipContent());

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileAcceptsTextFile(): void
    {
        $file = $this->createTestFile('document.txt', 'Plain text content');

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileAcceptsCsvFile(): void
    {
        $file = $this->createTestFile('data.csv', "Name,Email\nJohn,john@example.com");

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testGenerateSafeFilenameRemovesSpecialCharacters(): void
    {
        $file = $this->createTestFile('test@#$%^file.pdf', $this->getPdfContent());

        $safeName = $this->service->generateSafeFilename($file);

        // Special characters should be replaced with underscores
        $this->assertStringContainsString('test_', $safeName);
        $this->assertStringContainsString('file', $safeName);
        $this->assertStringEndsWith('.pdf', $safeName);
        $this->assertStringNotContainsString('@', $safeName);
        $this->assertStringNotContainsString('#', $safeName);
    }

    public function testGenerateSafeFilenameLimitsLength(): void
    {
        $longName = str_repeat('a', 100) . '.pdf';
        $file = $this->createTestFile($longName, $this->getPdfContent());

        $safeName = $this->service->generateSafeFilename($file);

        // Should be limited to 50 chars + underscore + unique ID + extension
        $this->assertLessThan(80, strlen($safeName));
    }

    public function testGenerateSafeFilenameAddsUniqueIdentifier(): void
    {
        $file = $this->createTestFile('test.pdf', $this->getPdfContent());

        $name1 = $this->service->generateSafeFilename($file);
        $name2 = $this->service->generateSafeFilename($file);

        $this->assertNotEquals($name1, $name2);
    }

    public function testGenerateSafeFilenamePreservesExtension(): void
    {
        $extensions = ['pdf', 'jpg', 'png', 'docx', 'xlsx', 'txt'];

        foreach ($extensions as $ext) {
            $file = $this->createTestFile("test.$ext", 'content');
            $safeName = $this->service->generateSafeFilename($file);
            $this->assertStringEndsWith(".$ext", $safeName);
        }
    }

    public function testGenerateSafeFilenameHandlesPathTraversalAttempt(): void
    {
        $file = $this->createTestFile('../../etc/passwd.pdf', $this->getPdfContent());

        $safeName = $this->service->generateSafeFilename($file);

        $this->assertStringNotContainsString('..', $safeName);
        $this->assertStringNotContainsString('/', $safeName);
    }

    public function testGetMaxFileSizeReturnsCorrectValue(): void
    {
        $maxSize = $this->service->getMaxFileSize();

        $this->assertEquals(10 * 1024 * 1024, $maxSize);
    }

    public function testGetAllowedExtensionsReturnsArray(): void
    {
        $extensions = $this->service->getAllowedExtensions();

        $this->assertIsArray($extensions);
        $this->assertContains('pdf', $extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('png', $extensions);
        $this->assertContains('docx', $extensions);
        $this->assertContains('xlsx', $extensions);
    }

    public function testGetAllowedMimeTypesReturnsArray(): void
    {
        $mimeTypes = $this->service->getAllowedMimeTypes();

        $this->assertIsArray($mimeTypes);
        $this->assertContains('application/pdf', $mimeTypes);
        $this->assertContains('image/jpeg', $mimeTypes);
        $this->assertContains('image/png', $mimeTypes);
        $this->assertContains('text/plain', $mimeTypes);
    }

    public function testValidateUploadedFileRejectsPhpFile(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File extension "php" is not allowed');

        $file = $this->createTestFile('script.php', '<?php echo "test"; ?>');

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileRejectsShellScript(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File extension "sh" is not allowed');

        $file = $this->createTestFile('script.sh', '#!/bin/bash\necho "test"');

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileRejectsExecutable(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File extension "bat" is not allowed');

        $file = $this->createTestFile('script.bat', '@echo off\necho test');

        $this->service->validateUploadedFile($file);
    }

    public function testValidateUploadedFileAcceptsGifImage(): void
    {
        $file = $this->createTestFile('image.gif', $this->getGifContent());

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileAcceptsZipArchive(): void
    {
        $file = $this->createTestFile('archive.zip', $this->getZipContent());

        $this->service->validateUploadedFile($file);

        $this->assertTrue(true);
    }

    public function testValidateUploadedFileHandlesInvalidFile(): void
    {
        $this->expectException(FileException::class);

        // Create an uploaded file that reports as invalid
        $invalidFile = new UploadedFile(
            $this->tempDir . '/test.pdf',
            'test.pdf',
            'application/pdf',
            UPLOAD_ERR_NO_FILE
        );

        $this->service->validateUploadedFile($invalidFile);
    }

    private function createTestFile(string $filename, string $content): UploadedFile
    {
        $path = $this->tempDir . '/' . basename($filename);
        file_put_contents($path, $content);

        return new UploadedFile(
            $path,
            $filename,
            null,
            null,
            true // test mode - skip is_uploaded_file check
        );
    }

    private function getPdfContent(): string
    {
        // Minimal valid PDF header
        return "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Count 0\n>>\nendobj\nxref\n0 3\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\ntrailer\n<<\n/Size 3\n/Root 1 0 R\n>>\nstartxref\n110\n%%EOF";
    }

    private function getJpegContent(): string
    {
        // JPEG magic bytes + minimal structure
        return hex2bin('ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc00011080001000103012200021101031101ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffc4001f0100030101010101010101010000000000000102030405060708090a0bffc400b51100020102040403040705040400010277000102031104052131061241510761711322328108144291a1b1c109233352f0156272d10a162434e125f11718191a262728292a35363738393a434445464748494a535455565758595a636465666768696a737475767778797a82838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae2e3e4e5e6e7e8e9eaf2f3f4f5f6f7f8f9faffda000c03010002110311003f00ffd9');
    }

    private function getPngContent(): string
    {
        // PNG magic bytes + minimal structure
        return hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c6300010000050001');
    }

    private function getGifContent(): string
    {
        // GIF magic bytes + minimal structure
        return hex2bin('474946383961010001008000000000ffffffff21f90401000001002c00000000010001000002024401003b');
    }

    private function getZipContent(): string
    {
        // Minimal ZIP file structure
        return hex2bin('504b0304140000000800000021000000000000000000000000000000000000504b0102140314000000080000002100000000000000000000000000000000504b05060000000001000100260000001a00000000');
    }
}
