<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\SystemSettingsBackedProvider;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phase 8QW-4 / 8M.4 — Resolver für Passwort-Mindestlänge mit Holding-Floor-Logik.
 *
 * Sicherheitsprinzip: Ein Mandant darf nur strenger sein als seine
 * Ancestor-Tenants (Floor-Pattern = Maximum über die Holding-Kette).
 *
 * W1-C (Policy-Wizard): Diese Klasse ist seit W1-C ein dünner Adapter über
 * den generischen {@see TenantSettingResolver}. Override-Mode ist FloorOnly
 * (longer min-length is stricter). Public API bleibt {@see resolveFor()}
 * mit `int`-Rückgabe, damit existierende Callsites (UserType etc.)
 * unverändert weiter funktionieren.
 *
 * Cache-Invalidation: invalidate(null) löscht den Gesamt-Cache (z.B. nach
 * globaler Setting-Änderung). invalidate($tenant) löscht nur den Tenant-Eintrag.
 *
 * TODO(9A / W1-A): Sobald TenantPolicySetting verfügbar ist, genügt es, einen
 * neuen SettingProviderInterface zu injizieren — die Logik selbst lebt im
 * generischen Resolver.
 */
class PasswordPolicyResolver
{
    public const SETTING_KEY = 'security.password_min_length';
    public const GLOBAL_FLOOR = 8;

    private readonly TenantSettingResolver $settingResolver;

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?TenantSettingResolver $settingResolver = null,
    ) {
        $this->settingResolver = $settingResolver ?? new TenantSettingResolver(
            provider: new SystemSettingsBackedProvider(
                systemSettingsRepository: $this->systemSettingsRepository,
                overrideModeMap: [self::SETTING_KEY => OverrideMode::FloorOnly],
            ),
            logger: $this->logger,
        );
    }

    /**
     * Gibt die effektive Passwort-Mindestlänge für den übergebenen Tenant zurück.
     *
     * Logik (Floor-Pattern):
     * 1. Globale Baseline aus SystemSettings 'security.password_min_length'
     * 2. Ancestor-Walk: jeder Ancestor mit eigenem Wert hebt das Floor.
     * 3. Eigener Tenant-Wert: darf das Floor nur erhöhen, niemals senken.
     */
    public function resolveFor(Tenant $tenant): int
    {
        $result = $this->settingResolver->resolveFor(
            $tenant,
            self::SETTING_KEY,
            self::GLOBAL_FLOOR,
        );

        $raw = $result->getValue();
        $effective = is_numeric($raw) ? (int) $raw : self::GLOBAL_FLOOR;

        // Sanity-Clamp: Mindestlänge nie unter 1.
        if ($effective < 1) {
            $this->logger->warning('GlobalPasswordMinLength invalid, falling back to GLOBAL_FLOOR', [
                'raw_value' => $raw,
                'fallback' => self::GLOBAL_FLOOR,
            ]);
            $effective = self::GLOBAL_FLOOR;
        }

        // Hard floor below the global constant — Floor-Pattern: stricter is allowed,
        // weaker than the constant never.
        if ($effective < self::GLOBAL_FLOOR) {
            $effective = self::GLOBAL_FLOOR;
        }

        return $effective;
    }

    /**
     * Cache nach Einstellungsänderung invalidieren. Pass-through to the
     * generic resolver.
     */
    public function invalidate(?Tenant $tenant = null): void
    {
        $this->settingResolver->invalidate($tenant, self::SETTING_KEY);
    }
}
