<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\SystemSettingsRepository;
use App\Service\QuickFixGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

#[AllowMockObjectsWithoutExpectations]
class QuickFixGuardTest extends TestCase
{
    private SystemSettingsRepository&MockObject $settings;
    private KernelInterface&MockObject $kernel;
    private QuickFixGuard $guard;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(SystemSettingsRepository::class);
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->tempDir = sys_get_temp_dir() . '/quick_fix_guard_' . uniqid();
        mkdir($this->tempDir . '/var', 0755, true);
        $this->kernel->method('getProjectDir')->willReturn($this->tempDir);
        $this->kernel->method('getEnvironment')->willReturn('prod');

        $this->guard = new QuickFixGuard($this->settings, $this->kernel);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            @unlink($this->tempDir . '/var/setup-token');
            @rmdir($this->tempDir . '/var');
            @rmdir($this->tempDir);
        }
    }

    #[Test]
    public function defaultsToOpenWhenAllTogglesOff(): void
    {
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $default
        );

        $this->assertTrue($this->guard->mayAccess(Request::create('/quick-fix')));
    }

    #[Test]
    public function fallbackUiEnabledDefaultTrue(): void
    {
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $default
        );

        $this->assertTrue($this->guard->fallbackUiEnabled());
    }

    #[Test]
    public function fallbackUiCanBeDisabled(): void
    {
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'fallback_ui_enabled' ? false : $default
        );

        $this->assertFalse($this->guard->fallbackUiEnabled());
    }

    #[Test]
    public function tokenGuardBlocksWithoutToken(): void
    {
        file_put_contents($this->tempDir . '/var/setup-token', 'secret123');
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'require_installer_token' ? true : $default
        );

        $this->assertFalse($this->guard->mayAccess(Request::create('/quick-fix')));
    }

    #[Test]
    public function tokenGuardAllowsWithMatchingToken(): void
    {
        file_put_contents($this->tempDir . '/var/setup-token', 'secret123');
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'require_installer_token' ? true : $default
        );

        $this->assertTrue($this->guard->mayAccess(Request::create('/quick-fix?token=secret123')));
    }

    #[Test]
    public function tokenGuardRejectsWrongToken(): void
    {
        file_put_contents($this->tempDir . '/var/setup-token', 'secret123');
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'require_installer_token' ? true : $default
        );

        $this->assertFalse($this->guard->mayAccess(Request::create('/quick-fix?token=wrong')));
    }

    #[Test]
    public function devOnlyGuardBlocksProd(): void
    {
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'allow_in_dev_only' ? true : $default
        );

        $this->assertFalse($this->guard->mayAccess(Request::create('/quick-fix')));
    }

    #[Test]
    public function ipAllowlistBlocksUnknownIp(): void
    {
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'ip_allowlist' ? '10.0.0.1, 192.168.1.5' : $default
        );

        $request = Request::create('/quick-fix');
        $request->server->set('REMOTE_ADDR', '8.8.8.8');

        $this->assertFalse($this->guard->mayAccess($request));
    }

    #[Test]
    public function ipAllowlistAllowsListedIp(): void
    {
        $this->settings->method('getSetting')->willReturnCallback(
            fn(string $cat, string $key, mixed $default) => $key === 'ip_allowlist' ? '10.0.0.1, 192.168.1.5' : $default
        );

        $request = Request::create('/quick-fix');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $this->assertTrue($this->guard->mayAccess($request));
    }

    #[Test]
    public function failsClosedToDefaultsWhenSettingsTableMissing(): void
    {
        $this->settings->method('getSetting')->willThrowException(new \RuntimeException('table missing'));

        // All guards default off → access allowed.
        $this->assertTrue($this->guard->mayAccess(Request::create('/quick-fix')));
        $this->assertTrue($this->guard->fallbackUiEnabled());
    }
}
