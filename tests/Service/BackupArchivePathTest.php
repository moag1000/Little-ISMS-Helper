<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Zip slip: the traversal check of BackupService::loadFromZip() compared a path
 * that was never normalised, so "files/../../public/x.php" passed and was written
 * outside public/uploads/ — already on validate / preview of an uploaded backup.
 */
final class BackupArchivePathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent traversal' => ['../../config/packages/security.yaml'];
        yield 'nested traversal' => ['tenants/1/../../../public/x.php'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'backslash' => ['tenants\\..\\..\\x.txt'];
        yield 'nul byte' => ["doc.pdf\0.txt"];
        yield 'dot file' => ['tenants/1/.htaccess'];
        yield 'user ini' => ['.user.ini'];
        yield 'php' => ['tenants/1/shell.php'];
        yield 'double extension' => ['tenants/1/shell.php.jpg'];
        yield 'phar' => ['tenants/1/x.PHAR'];
        yield 'empty segment' => ['tenants//x.pdf'];
        // Served from the application's origin: stored XSS
        yield 'html' => ['documents/report.html'];
        yield 'svg' => ['tenant_logos/logo.svg'];
        yield 'javascript' => ['documents/app.js'];
        yield 'no extension' => ['documents/README'];
    }

    #[Test]
    #[DataProvider('unsafePaths')]
    public function rejectsUnsafeArchivePaths(string $path): void
    {
        self::assertFalse(BackupService::isSafeArchivePath($path));
    }

    #[Test]
    public function acceptsOrdinaryUploads(): void
    {
        self::assertTrue(BackupService::isSafeArchivePath('tenants/3/documents/policy-v2.pdf'));
        self::assertTrue(BackupService::isSafeArchivePath('users/7/avatar.png'));
        self::assertTrue(BackupService::isSafeArchivePath('training-materials/Schulung 2026.pptx'));
    }
}
