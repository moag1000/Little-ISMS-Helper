<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Kpi\KpiCatalog;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Dashboards pick KPIs by key from the catalog instead of computing their own
 * (UI wave D): `{% set k = kpi('risk_exposure_eur') %}`, `{{ k|kpi_value }}`.
 * The tile macro `_components/_kpi_tile.html.twig` wraps both.
 */
final class KpiExtension
{
    public function __construct(
        private readonly KpiCatalog $catalog,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return array<string, mixed>|null */
    #[AsTwigFunction('kpi')]
    public function kpi(string $key): ?array
    {
        return $this->catalog->get($key);
    }

    /** Value with unit, numbers in the request locale: "1.250.000 €", "85 %", "72/100". */
    #[AsTwigFilter('kpi_value')]
    public function kpiValue(?array $kpi): string
    {
        $value = $kpi['value'] ?? null;
        if ($kpi === null || $value === null || ($kpi['na'] ?? false)) {
            return '–';
        }
        $unit = (string) ($kpi['unit'] ?? '');
        if (!is_numeric($value)) {
            return $value . $unit;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'de';
        $decimals = (float) $value === floor((float) $value) ? 0 : 1;
        $formatted = number_format((float) $value, $decimals, ...(str_starts_with($locale, 'de') ? [',', '.'] : ['.', ',']));

        return match ($unit) {
            '' => $formatted,
            '€', '%' => $formatted . "\u{a0}" . $unit,
            default => $formatted . $unit,
        };
    }
}
