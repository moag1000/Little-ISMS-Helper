<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\HealthAutoFixService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies the Consultant-Review A3 / ISB MINOR-3 audit coverage of
 * HealthAutoFixService: every public fix-method must route its return
 * payload through AuditLogger::logCustom with an admin.health_fix.*
 * action.
 *
 * Idempotency contract (ISB MINOR-6 analog): running a fix-method twice
 * on the same state must not produce diverging results. For permission
 * fixes + cache/log cleanup the second call is a no-op and is still
 * audited (success=true, nothing-to-do semantics).
 */
final class HealthAutoFixServiceAuditTest extends TestCase
{
    private string $projectDir;
    private string $cacheDir;
    private string $logsDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/health_audit_' . uniqid();
        $this->cacheDir = $this->projectDir . '/var/cache';
        $this->logsDir = $this->projectDir . '/var/log';
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testFixCachePermissionsWritesAuditEntry(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                $this->equalTo('admin.health_fix.fix_cache_permissions'),
                $this->equalTo('HealthAutoFix'),
                $this->isNull(),
                $this->isNull(),
                $this->callback(static fn(array $payload): bool => array_key_exists('success', $payload)),
                $this->isString(),
            );

        $service = new HealthAutoFixService(
            $this->projectDir,
            $this->cacheDir,
            $this->logsDir,
            new NullLogger(),
            $auditLogger,
        );

        $service->fixCachePermissions();
    }

    public function testCleanOldLogsAuditsDaysToKeep(): void
    {
        mkdir($this->logsDir, 0775, true);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                $this->equalTo('admin.health_fix.clean_old_logs'),
                $this->equalTo('HealthAutoFix'),
                $this->isNull(),
                $this->isNull(),
                // retention value flows into the audit payload so a custom
                // (non-default) value is always reviewable.
                $this->callback(static fn(array $p): bool => ($p['days_to_keep'] ?? null) === 45),
                $this->isString(),
            );

        $service = new HealthAutoFixService(
            $this->projectDir,
            $this->cacheDir,
            $this->logsDir,
            new NullLogger(),
            $auditLogger,
        );

        $service->cleanOldLogs(45);
    }

    public function testFixCachePermissionsIsIdempotent(): void
    {
        // Second invocation must not change the outcome (both success) —
        // a re-run is legal, contract: no diverging DB/FS state.
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->exactly(2))
            ->method('logCustom');

        $service = new HealthAutoFixService(
            $this->projectDir,
            $this->cacheDir,
            $this->logsDir,
            new NullLogger(),
            $auditLogger,
        );

        $first = $service->fixCachePermissions();
        $second = $service->fixCachePermissions();

        self::assertTrue($first['success']);
        self::assertTrue($second['success']);
        // Second call is a no-op / "already writable" — idempotent.
        self::assertSame($first['success'], $second['success']);
    }

    public function testServiceWorksWithoutAuditLogger(): void
    {
        // Backwards-compat: existing wiring constructs the service without
        // the auditor (default null). Calls must not explode.
        $service = new HealthAutoFixService(
            $this->projectDir,
            $this->cacheDir,
            $this->logsDir,
            new NullLogger(),
        );

        $result = $service->fixCachePermissions();
        self::assertIsArray($result);
        self::assertArrayHasKey('success', $result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}
