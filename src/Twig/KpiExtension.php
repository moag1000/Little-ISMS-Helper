<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ISMSObjective;
use App\Service\Kpi\KpiCatalog;
use App\Service\Kpi\ObjectiveKpiProgress;
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
        private readonly ?ObjectiveKpiProgress $objectiveProgress = null,
    ) {
    }

    /**
     * Progress of an ISMS objective (ISO 27001 6.2 d / 9.1) for lists, drawer and
     * detail page: computed from the linked KPI, else from the value kept by hand.
     *
     * @return array{percent: int, current: float|string|null, computed: bool}
     */
    #[AsTwigFunction('objective_progress')]
    public function objectiveProgress(ISMSObjective $objective): array
    {
        $key = $objective->getKpiKey();
        $current = $key !== null ? $this->objectiveProgress?->current($objective) : null;
        $percent = $key !== null ? ObjectiveKpiProgress::progress($key, $current, $objective->getTargetValue()) : null;

        return $percent !== null
            ? ['percent' => $percent, 'current' => $current, 'computed' => true]
            : ['percent' => max(0, min(100, $objective->getProgressPercentage())), 'current' => $objective->getCurrentValue(), 'computed' => false];
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
