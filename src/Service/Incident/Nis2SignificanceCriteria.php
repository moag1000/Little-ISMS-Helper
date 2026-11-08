<?php

declare(strict_types=1);

namespace App\Service\Incident;

use App\Entity\Tenant;

/**
 * The organisation's thresholds for the NIS-2 pre-check "significant incident"
 * (NIS-2 Art. 23 (3)), kept in the tenant settings (key "nis2_significance").
 * Defaults are the orientation values of Implementing Regulation (EU)
 * 2024/2690 Art. 3; the organisation adjusts them to its size and sector
 * under Admin > Incident-SLAs. The pre-check only suggests — the organisation
 * decides whether an incident is reportable.
 */
final class Nis2SignificanceCriteria
{
    public const string SETTINGS_KEY = 'nis2_significance';
    public const int DEFAULT_FINANCIAL_LOSS_EUR = 500000;
    public const int DEFAULT_AFFECTED_PERSONS = 100;

    public static function financialLossEur(?Tenant $tenant): int
    {
        return self::value($tenant, 'financial_loss_eur', self::DEFAULT_FINANCIAL_LOSS_EUR);
    }

    public static function affectedPersons(?Tenant $tenant): int
    {
        return self::value($tenant, 'affected_persons', self::DEFAULT_AFFECTED_PERSONS);
    }

    public static function set(Tenant $tenant, int $financialLossEur, int $affectedPersons): void
    {
        $settings = $tenant->getSettings() ?? [];
        $settings[self::SETTINGS_KEY] = [
            'financial_loss_eur' => max(1, $financialLossEur),
            'affected_persons' => max(1, $affectedPersons),
        ];
        $tenant->setSettings($settings);
    }

    private static function value(?Tenant $tenant, string $key, int $default): int
    {
        $value = (int) (($tenant?->getSettings() ?? [])[self::SETTINGS_KEY][$key] ?? $default);

        return $value > 0 ? $value : $default;
    }
}
