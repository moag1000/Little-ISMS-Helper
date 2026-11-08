<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\QuickFixJobStatusController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Quick-Fix status endpoint is reachable without login (QuickFixGuard). It
 * returned the status file of ANY async job — exports with their download URL,
 * backups with the tenant — not only Quick-Fix jobs.
 */
final class QuickFixJobStatusScopeTest extends TestCase
{
    #[Test]
    public function onlyQuickFixJobsAreExposed(): void
    {
        self::assertTrue(QuickFixJobStatusController::isQuickFixJob(['name' => 'quick_fix.apply']));
        self::assertTrue(QuickFixJobStatusController::isQuickFixJob(['name' => 'quick_fix.repair_all']));
        self::assertFalse(QuickFixJobStatusController::isQuickFixJob(['name' => 'admin.export_risks']));
        self::assertFalse(QuickFixJobStatusController::isQuickFixJob(['name' => 'admin.backup.create']));
        self::assertFalse(QuickFixJobStatusController::isQuickFixJob([]));
    }
}
