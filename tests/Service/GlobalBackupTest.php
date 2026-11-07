<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A global backup must run through entities whose repository blocks
 * findAll() against tenant leaks (ComplianceMappingRepository).
 */
final class GlobalBackupTest extends KernelTestCase
{
    #[Test]
    public function aGlobalBackupRunsThroughEntitiesWithABlockedFindAll(): void
    {
        self::bootKernel();
        $backup = static::getContainer()->get(BackupService::class)->createBackup(false, false, false);

        self::assertArrayHasKey('ComplianceMapping', $backup['data']);
        self::assertArrayHasKey('Tenant', $backup['data']);
    }
}
