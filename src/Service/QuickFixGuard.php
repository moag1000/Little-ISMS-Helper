<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SystemSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Decides whether the public-fallback Quick-Fix UI is reachable for the
 * current request.
 *
 * Two levels:
 *
 *  - mayAccess(): the status page, "apply pending migrations" and job
 *    polling. Defaults to OPEN (no auth) so an operator can recover an
 *    instance whose login is broken by a pending migration — applying the
 *    vendored migrations is idempotent and safe-by-design.
 *  - mayRunPrivileged(): everything that changes data or drops schema
 *    (destructive reconcile, force-mark-executed, phantom-diff marking,
 *    schema force-update, data repair). Needs mayAccess() AND either a
 *    logged-in ROLE_SUPER_ADMIN or the installer token. Before this split an
 *    anonymous visitor could reassign tenants or DROP columns on a default
 *    install.
 *
 * Admins can tighten mayAccess() via three optional toggles in the
 * `quick_fix` settings category:
 *
 *  - require_installer_token  — match query param / cookie against
 *                               `var/setup-token` (random token written by
 *                               composer post-install-cmd)
 *  - allow_in_dev_only        — only reachable in dev environment
 *  - ip_allowlist             — comma-separated exact IPs and/or CIDR ranges
 *                               (e.g. `10.0.0.5, 192.168.1.0/24, ::1`),
 *                               matched via Symfony IpUtils; empty = disabled
 */
class QuickFixGuard
{
    public function __construct(
        private readonly SystemSettingsRepository $settings,
        private readonly KernelInterface $kernel,
        private readonly ?Security $security = null,
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
        if ($allowlist !== []) {
            $clientIp = (string) $request->getClientIp();
            // IpUtils::checkIp matches both exact IPs and CIDR ranges (IPv4 +
            // IPv6). A bare in_array() never matched a CIDR entry, so an
            // operator who entered their subnet (the natural notation) locked
            // everyone — including themselves — out.
            if ($clientIp === '' || !IpUtils::checkIp($clientIp, $allowlist)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Data-changing / destructive Quick-Fix actions: a super admin session or
     * the installer token (query param / cookie, see tokenMatches()).
     */
    public function mayRunPrivileged(Request $request): bool
    {
        if (!$this->mayAccess($request)) {
            return false;
        }
        if ($this->tokenMatches($request)) {
            return true;
        }
        try {
            return $this->security?->isGranted('ROLE_SUPER_ADMIN') ?? false;
        } catch (\Throwable) {
            // Broken user table / session: fall back to the token path only.
            return false;
        }
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
