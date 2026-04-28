<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SystemSettingsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Decides whether the public-fallback Quick-Fix UI is reachable for the
 * current request.
 *
 * Defaults to OPEN (no auth) — the only action behind it is "apply pending
 * migrations" which is idempotent and safe-by-design (only ships vendored
 * migrations from this repo). Admins can tighten access via three optional
 * toggles in the `quick_fix` settings category:
 *
 *  - require_installer_token  — match query param / cookie against
 *                               `var/setup-token` (random token written by
 *                               composer post-install-cmd)
 *  - allow_in_dev_only        — only reachable in dev environment
 *  - ip_allowlist             — comma-separated CIDR-less IPs / hostnames;
 *                               empty list = disabled
 */
class QuickFixGuard
{
    public function __construct(
        private readonly SystemSettingsRepository $settings,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function mayAccess(Request $request): bool
    {
        if ($this->requireInstallerToken() && !$this->tokenMatches($request)) {
            return false;
        }
        if ($this->allowInDevOnly() && $this->kernel->getEnvironment() !== 'dev') {
            return false;
        }
        $allowlist = $this->ipAllowlist();
        if ($allowlist !== [] && !in_array((string) $request->getClientIp(), $allowlist, true)) {
            return false;
        }
        return true;
    }

    public function fallbackUiEnabled(): bool
    {
        return (bool) $this->readSetting('fallback_ui_enabled', true);
    }

    public function isTokenRequired(): bool
    {
        return $this->requireInstallerToken();
    }

    private function requireInstallerToken(): bool
    {
        return (bool) $this->readSetting('require_installer_token', false);
    }

    private function allowInDevOnly(): bool
    {
        return (bool) $this->readSetting('allow_in_dev_only', false);
    }

    /** @return list<string> */
    private function ipAllowlist(): array
    {
        $raw = $this->readSetting('ip_allowlist', '');
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function tokenMatches(Request $request): bool
    {
        $expected = $this->loadInstallerToken();
        if ($expected === null) {
            return false;
        }
        $supplied = (string) ($request->query->get('token') ?? $request->cookies->get('quick_fix_token', ''));
        if ($supplied === '') {
            return false;
        }
        return hash_equals($expected, $supplied);
    }

    private function loadInstallerToken(): ?string
    {
        $path = $this->kernel->getProjectDir() . '/var/setup-token';
        if (!is_readable($path)) {
            return null;
        }
        $contents = trim((string) @file_get_contents($path));
        return $contents === '' ? null : $contents;
    }

    private function readSetting(string $key, mixed $default): mixed
    {
        // The settings table itself may not exist on a freshly-broken schema.
        // Failing-closed to defaults keeps the guard usable.
        try {
            return $this->settings->getSetting('quick_fix', $key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
