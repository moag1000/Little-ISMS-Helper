<?php

declare(strict_types=1);

namespace App\Tests\Service\Upload;

use App\Service\Upload\ProtectedUploads;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S7: uploaded evidence lives below var/uploads, outside the web root. The stored value
 * on the record stays '/uploads/documents/<file>'; resolve() maps it and still finds a
 * file that has not been moved yet, and never leaves the upload areas.
 */
final class ProtectedUploadsTest extends TestCase
{
    private string $root;
    private ProtectedUploads $uploads;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/protected_uploads_' . uniqid();
        mkdir($this->root . '/public/uploads/documents', 0755, true);
        mkdir($this->root . '/public/uploads/training-materials', 0755, true);
        mkdir($this->root . '/public/uploads/tenants', 0755, true);
        mkdir($this->root . '/var', 0755, true);
        $this->uploads = new ProtectedUploads($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    #[Test]
    public function newFilesGoBelowVarAndResolveFromTheStoredPath(): void
    {
        $dir = $this->uploads->directory(ProtectedUploads::DOCUMENTS);
        self::assertSame($this->root . '/var/uploads/documents', $dir);
        file_put_contents($dir . '/policy.pdf', '%PDF');

        $stored = $this->uploads->storedPath(ProtectedUploads::DOCUMENTS, 'policy.pdf');

        self::assertSame('/uploads/documents/policy.pdf', $stored);
        self::assertSame($dir . '/policy.pdf', $this->uploads->resolve($stored));
        self::assertSame($dir . '/policy.pdf', $this->uploads->resolve('uploads/documents/policy.pdf'));
    }

    #[Test]
    public function aFileNotYetMovedIsStillFound(): void
    {
        file_put_contents($this->root . '/public/uploads/documents/old.pdf', '%PDF');

        self::assertSame(
            $this->root . '/public/uploads/documents/old.pdf',
            $this->uploads->resolve('/uploads/documents/old.pdf'),
        );
    }

    #[Test]
    public function nothingOutsideTheUploadAreasResolves(): void
    {
        file_put_contents($this->root . '/public/uploads/tenants/logo.png', 'png');
        file_put_contents($this->root . '/secret.txt', 'x');

        self::assertNull($this->uploads->resolve(null));
        self::assertNull($this->uploads->resolve('virtual:policy-wizard/x.pdf'));
        self::assertNull($this->uploads->resolve('/uploads/documents/../../secret.txt'));
        self::assertNull($this->uploads->resolve('/uploads/tenants/logo.png'), 'logos are not evidence');
        self::assertNull($this->uploads->resolve($this->root . '/secret.txt'), 'absolute paths only below var/');
        self::assertNull($this->uploads->resolve('/etc/passwd'));
    }

    #[Test]
    public function legacyFilesMoveWithoutOverwriting(): void
    {
        file_put_contents($this->root . '/public/uploads/documents/a.pdf', 'A');
        file_put_contents($this->root . '/public/uploads/training-materials/t.pdf', 'T');
        file_put_contents($this->root . '/public/uploads/documents/.gitkeep', '');
        file_put_contents($this->root . '/public/uploads/tenants/logo.png', 'png');
        file_put_contents($this->uploads->directory(ProtectedUploads::DOCUMENTS) . '/dup.pdf', 'new');
        file_put_contents($this->root . '/public/uploads/documents/dup.pdf', 'old');

        $result = $this->uploads->moveLegacyFiles();

        self::assertSame(['moved' => 2, 'kept' => 1, 'failed' => 0, 'blocked' => false], $result);
        self::assertStringEqualsFile($this->root . '/var/uploads/documents/a.pdf', 'A');
        self::assertStringEqualsFile($this->root . '/var/uploads/training-materials/t.pdf', 'T');
        self::assertStringEqualsFile($this->root . '/var/uploads/documents/dup.pdf', 'new');
        self::assertFileDoesNotExist($this->root . '/public/uploads/documents/a.pdf');
        self::assertFileExists($this->root . '/public/uploads/tenants/logo.png', 'logos stay public');
        self::assertSame([], array_values(array_filter(
            $this->uploads->legacyFiles(),
            static fn (string $f): bool => !str_ends_with($f, '/dup.pdf'),
        )));
        self::assertSame(['moved' => 0, 'kept' => 1, 'failed' => 0, 'blocked' => false], $this->uploads->moveLegacyFiles());
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
