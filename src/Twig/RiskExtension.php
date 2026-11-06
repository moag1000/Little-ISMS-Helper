<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Risk;
use App\Risk\RiskMatrixThresholds;
use App\Service\Risk\RiskBusinessSummary;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig Extension for risk-matrix presentation helpers.
 *
 * Exposes the band-thresholds from {@see RiskMatrixThresholds} (SSoT)
 * to templates so views never have to hard-code "20-25" / "12-19" etc.
 * — those strings used to drift between yaml/twig/service.
 *
 * Risk-Schwellen single-source-of-truth
 * via RiskMatrixThresholds. The legacy `risk.filter.level_*` and
 * `risk.index.risk_level.*` translation blocks duplicated the canonical
 * `risk.level.<band>` labels — kept the canonical block, drove templates
 * through `risk_level_label(band)` / `risk_level_band(score)` so all band
 * resolution flows through one code path that delegates to
 * RiskMatrixThresholds::classify().
 */
final class RiskExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RiskBusinessSummary $businessSummary,
    ) {
    }

    /** "Hoch (16) · ca. 120.000 €/Jahr · Asset: Webshop" — {@see RiskBusinessSummary}. */
    #[AsTwigFunction('risk_business_summary')]
    public function riskBusinessSummary(Risk $risk): string
    {
        return $this->businessSummary->line($risk);
    }

    /**
     * Render the score-range for a band, e.g. risk_threshold('critical') → "20–25".
     *
     * Empty string for unknown levels.
     */
    #[AsTwigFunction('risk_threshold')]
    public function riskThreshold(string $level): string
    {
        $bands = RiskMatrixThresholds::getBands();
        if (!isset($bands[$level])) {
            return '';
        }

        return sprintf('%d–%d', $bands[$level]['min'], $bands[$level]['max']);
    }

    /**
     * Return the localised label for a
     * risk band (`critical|high|medium|low`). All template call-sites that
     * previously read `risk.filter.level_<band>` or
     * `risk.index.risk_level.<band>` now funnel through this helper, so
     * the label can only come from a single yaml key (`risk.level.<band>`).
     */
    #[AsTwigFunction('risk_level_label')]
    public function riskLevelLabel(string $level): string
    {
        $bands = RiskMatrixThresholds::getBands();
        if (!isset($bands[$level])) {
            return '';
        }

        return $this->translator->trans('risk.level.' . $level, [], 'risk');
    }

    /**
     * Classify a numeric score into a band
     * via the SSoT and return the band identifier. Convenience for templates
     * that need to colour a value but only have the score in hand.
     */
    #[AsTwigFunction('risk_level_band')]
    public function riskLevelBand(int $score): string
    {
        return RiskMatrixThresholds::classify($score);
    }
}
