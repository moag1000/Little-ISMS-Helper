<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;

/**
 * Phase 8L.F3 — Aufgelöstes E-Mail-Branding (Value-Object).
 *
 * `fromAddress` und `fromName` sind garantiert gesetzt (Fallback auf
 * Hardcoded-Default). Logo/Footer/Support sind optional.
 */
final readonly class EmailBranding
{
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public ?string $logoUrl,
        public ?string $footerText,
        public ?string $supportAddress,
    ) {
    }
}

/**
 * Phase 8L.F3 — Fallback-Kaskade für E-Mail-Branding.
 *
 * Reihenfolge:
 *   1. Tenant-Feld gesetzt? → verwenden
 *   2. Ancestor-Walk (getAllAncestors: immediate parent first, root last)
 *   3. SystemSettings (category=email, key=default_from_email / _from_name / …)
 *   4. Hardcoded-Default ('noreply@little-isms.local' / 'Little ISMS Helper')
 *
 * Der Ancestor-Walk ist bereits 8M-Behavior (Holding-Fallback) — im F3-Scope
 * explizit eingebaut, weil Branding ohne Inheritance unsinnig ist.
 *
 * Nicht final wegen PHPUnit-Mock-Kompatibilität.
 */
class TenantEmailBrandingResolver
{
    private const string DEFAULT_FROM_ADDRESS = 'noreply@little-isms.local';
    private const string DEFAULT_FROM_NAME = 'Little ISMS Helper';

    /** @var array<int, EmailBranding> */
    private array $cache = [];

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
    ) {
    }

    public function resolveFor(?Tenant $tenant = null): EmailBranding
    {
        $cacheKey = $tenant?->getId() ?? 0;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $fromAddress = $this->walk($tenant, fn (Tenant $t) => $t->getEmailFromAddress())
            ?? $this->fromSystemSettings('default_from_email')
            ?? self::DEFAULT_FROM_ADDRESS;

        $fromName = $this->walk($tenant, fn (Tenant $t) => $t->getEmailFromName())
            ?? $this->fromSystemSettings('default_from_name')
            ?? self::DEFAULT_FROM_NAME;

        $logoUrl = $this->walk($tenant, fn (Tenant $t) => $t->getEmailLogoUrl())
            ?? $this->fromSystemSettings('default_logo_url');

        $footerText = $this->walk($tenant, fn (Tenant $t) => $t->getEmailFooterText())
            ?? $this->fromSystemSettings('default_footer_text');

        $supportAddress = $this->walk($tenant, fn (Tenant $t) => $t->getEmailSupportAddress())
            ?? $this->fromSystemSettings('default_support_address');

        return $this->cache[$cacheKey] = new EmailBranding(
            $fromAddress,
            $fromName,
            $logoUrl,
            $footerText,
            $supportAddress,
        );
    }

    public function invalidate(?Tenant $tenant = null): void
    {
        unset($this->cache[$tenant?->getId() ?? 0]);
    }

    /**
     * Walk from Tenant through ancestors; return first non-null value.
     */
    private function walk(?Tenant $tenant, callable $getter): ?string
    {
        if (!$tenant instanceof Tenant) {
            return null;
        }
        $value = $getter($tenant);
        if ($value !== null && $value !== '') {
            return $value;
        }
        foreach ($tenant->getAllAncestors() as $ancestor) {
            $value = $getter($ancestor);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function fromSystemSettings(string $key): ?string
    {
        $raw = $this->systemSettingsRepository->getSetting('email', $key, null);
        if ($raw === null || $raw === '') {
            return null;
        }
        return (string) $raw;
    }
}
