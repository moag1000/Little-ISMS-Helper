<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validate, preview, restore and delete accepted only .json/.gz names, so a
 * backup with documents (.zip, the default once files exist) or an uploaded
 * .zip could be downloaded but never restored or deleted from the UI.
 */
final class BackupFilenameTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'zip with files' => ['backup_2026-11-07_04-10-00.zip', true];
        yield 'uploaded zip' => ['uploaded_2026-11-07_04-10-00.zip', true];
        yield 'json.gz' => ['backup_2026-11-07_04-10-00.json.gz', true];
        yield 'json' => ['backup_2026-11-07_04-10-00.json', true];
        yield 'legacy gz' => ['uploaded_2026-11-07_04-10-00.gz', true];
        yield 'sidecar' => ['backup_2026-11-07_04-10-00.zip.meta.json', false];
        yield 'traversal' => ['backup_../../etc/passwd.json', false];
        yield 'other prefix' => ['quickfix_logical_x.json', false];
        yield 'other extension' => ['backup_2026-11-07.php', false];
    }

    #[Test]
    #[DataProvider('names')]
    public function oneFilenameRuleForAllBackupEndpoints(string $filename, bool $valid): void
    {
        self::assertSame($valid, BackupService::isBackupFilename($filename));
    }
}
